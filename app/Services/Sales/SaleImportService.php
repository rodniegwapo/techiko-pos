<?php

namespace App\Services\Sales;

use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleImport;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads a CSV of past sales (one row per item, rows with the same receipt_no make one sale),
 * checks it row by row, and writes the sales dated on the day they really happened.
 * Historical sales skip the cash drawer and (unless asked) stock, so today's books are not disturbed.
 */
class SaleImportService
{
    public const MAX_ROWS = 2000;

    public const HEADERS = ['receipt_no', 'date', 'product_barcode_or_sku', 'quantity', 'unit_price', 'payment_method', 'reference'];

    public const PAYMENT_METHODS = ['cash', 'card', 'e-wallet', 'bank'];

    private const HEADER_ALIASES = [
        'receipt' => 'receipt_no',
        'receipt_number' => 'receipt_no',
        'invoice' => 'receipt_no',
        'invoice_no' => 'receipt_no',
        'sale_date' => 'date',
        'product' => 'product_barcode_or_sku',
        'barcode' => 'product_barcode_or_sku',
        'sku' => 'product_barcode_or_sku',
        'product_barcode' => 'product_barcode_or_sku',
        'qty' => 'quantity',
        'price' => 'unit_price',
        'payment' => 'payment_method',
        'ref' => 'reference',
    ];

    public function __construct(private InventoryService $inventory) {}

    /** The downloadable template: header row plus two example receipts. */
    public static function templateRows(): array
    {
        return [
            self::HEADERS,
            ['R-1001', '2026-09-15', '4800000000011', '2', '45.00', 'cash', ''],
            ['R-1001', '2026-09-15', '4800000000028', '1.5', '', 'cash', ''],
            ['R-1002', '2026-09-16', 'SKU-0003', '1', '120.00', 'e-wallet', 'GCASH-REF-123'],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>}
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['rows' => [], 'errors' => [['line' => 0, 'message' => 'The file could not be read.']]];
        }

        $rows = [];
        $errors = [];
        $columns = null;
        $line = 0;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($line === 1) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($cells[0] ?? ''));
            }
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            if ($columns === null) {
                $columns = array_map(fn ($c) => $this->normalizeHeader((string) $c), $cells);
                $missing = array_diff(['receipt_no', 'date', 'product_barcode_or_sku', 'quantity', 'payment_method'], $columns);
                if ($missing !== []) {
                    $errors[] = ['line' => 1, 'message' => 'Missing column(s): '.implode(', ', $missing).'. Download the template and use its header row.'];
                    break;
                }

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'message' => 'Too many rows. Import at most '.self::MAX_ROWS.' rows at a time.'];
                break;
            }

            $row = ['line' => $line];
            foreach ($columns as $i => $name) {
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($columns === null && $errors === []) {
            $errors[] = ['line' => 0, 'message' => 'The file is empty.'];
        } elseif ($rows === [] && $errors === []) {
            $errors[] = ['line' => 0, 'message' => 'The file has no sales rows.'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Groups rows into sales and reports every problem by line. Nothing is written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sales: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, total: float}
     */
    public function validate(array $rows, string $domain, ?int $locationId): array
    {
        $errors = [];
        $keys = collect($rows)->pluck('product_barcode_or_sku')->filter()->unique()->values();
        $byBarcode = Product::query()->forDomain($domain)->whereIn('barcode', $keys)->get()->keyBy('barcode');
        $bySku = Product::query()->forDomain($domain)->whereIn('SKU', $keys)->get()->groupBy('SKU');

        $sales = [];
        foreach ($rows as $row) {
            $line = (int) $row['line'];
            $fail = function (string $message) use (&$errors, $line) {
                $errors[] = ['line' => $line, 'message' => $message];
            };
            $before = count($errors);

            $receipt = (string) ($row['receipt_no'] ?? '');
            if ($receipt === '') {
                $fail('receipt_no is empty.');
            }

            $date = null;
            try {
                $date = Carbon::createFromFormat('!Y-m-d', (string) ($row['date'] ?? ''));
                if ($date === false || $date->format('Y-m-d') !== $row['date']) {
                    $date = null;
                }
            } catch (\Throwable) {
            }
            if (! $date) {
                $fail('date must look like 2026-09-15 (year-month-day).');
            } elseif ($date->greaterThan(Carbon::today())) {
                $fail('date is in the future. Only past or today\'s sales can be imported.');
            }

            $product = null;
            $key = (string) ($row['product_barcode_or_sku'] ?? '');
            if ($key === '') {
                $fail('product_barcode_or_sku is empty.');
            } elseif ($byBarcode->has($key)) {
                $product = $byBarcode->get($key);
            } elseif (($matches = $bySku->get($key, collect()))->count() === 1) {
                $product = $matches->first();
            } elseif ($matches->count() > 1) {
                $fail("\"{$key}\" matches more than one product by SKU. Use the barcode instead.");
            } else {
                $fail("No product with barcode or SKU \"{$key}\".");
            }

            $quantity = null;
            if (! is_numeric($row['quantity'] ?? null) || (float) $row['quantity'] < Quantity::MIN) {
                $fail('quantity must be a number greater than 0.');
            } else {
                $quantity = Quantity::round($row['quantity']);
                if ($product && ! Quantity::fits($product, $quantity)) {
                    $fail("{$product->name} is sold by the {$product->quantityUnit()}: use a whole number.");
                }
            }

            $price = null;
            if (($row['unit_price'] ?? '') !== '') {
                if (! is_numeric($row['unit_price']) || (float) $row['unit_price'] < 0) {
                    $fail('unit_price must be a number, 0 or more (or leave it empty to use the product price).');
                } else {
                    $price = round((float) $row['unit_price'], 2);
                }
            } elseif ($product) {
                $price = round($product->priceAt($locationId), 2);
            }

            $method = $this->normalizePaymentMethod((string) ($row['payment_method'] ?? ''));
            if (! $method) {
                $fail('payment_method must be one of: '.implode(', ', self::PAYMENT_METHODS).'.');
            }

            if (count($errors) > $before || $receipt === '') {
                continue;
            }

            if (isset($sales[$receipt])) {
                if ($sales[$receipt]['date'] !== $date->toDateString()) {
                    $fail("Receipt {$receipt} has different dates. One receipt must have one date.");

                    continue;
                }
                if ($sales[$receipt]['payment_method'] !== $method) {
                    $fail("Receipt {$receipt} has different payment methods. One receipt must have one payment method.");

                    continue;
                }
            } else {
                $sales[$receipt] = [
                    'receipt_no' => $receipt,
                    'date' => $date->toDateString(),
                    'payment_method' => $method,
                    'reference' => (string) ($row['reference'] ?? ''),
                    'items' => [],
                ];
            }

            $sales[$receipt]['items'][] = [
                'line' => $line,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $price,
            ];
        }

        // A receipt with any bad line is left out whole, so a sale is never imported half-complete.
        $badLines = collect($errors)->pluck('line')->all();
        $sales = collect($sales)
            ->reject(fn ($sale) => collect($rows)->contains(
                fn ($r) => ($r['receipt_no'] ?? '') === $sale['receipt_no'] && in_array((int) $r['line'], $badLines, true)
            ))
            ->values()
            ->map(function ($sale) {
                $sale['total'] = round(collect($sale['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_price']), 2);

                return $sale;
            })
            ->all();

        return [
            'sales' => $sales,
            'errors' => $errors,
            'total' => round(collect($sales)->sum('total'), 2),
        ];
    }

    /**
     * Writes the validated sales in one transaction.
     *
     * @param  list<array<string, mixed>>  $sales
     * @param  array{deduct_stock?: bool}  $options
     */
    public function commit(array $sales, string $domain, InventoryLocation $location, User $user, string $fileName, string $fileHash, int $rowsCount, array $options = []): SaleImport
    {
        $deductStock = (bool) ($options['deduct_stock'] ?? false);

        return DB::transaction(function () use ($sales, $domain, $location, $user, $fileName, $fileHash, $rowsCount, $deductStock) {
            $import = SaleImport::query()->create([
                'domain' => $domain,
                'user_id' => $user->id,
                'location_id' => $location->id,
                'file_name' => Str::limit($fileName, 240, ''),
                'file_hash' => $fileHash,
                'rows_count' => $rowsCount,
                'sales_count' => count($sales),
                'options' => ['deduct_stock' => $deductStock],
            ]);

            foreach ($sales as $row) {
                $when = Carbon::parse($row['date'])->setTime(12, 0);

                $sale = Sale::query()->create([
                    'user_id' => $user->id,
                    'location_id' => $location->id,
                    'domain' => $domain,
                    'import_id' => $import->id,
                    'payment_status' => 'paid',
                    'invoice_number' => 'IMP-'.$import->id.'-'.Str::limit(preg_replace('/[^A-Za-z0-9_-]+/', '', $row['receipt_no']) ?: Str::random(4), 30, ''),
                    'transaction_date' => $when,
                    'payment_method' => $row['payment_method'],
                    'payment_reference' => $row['reference'] !== '' ? Str::limit($row['reference'], 190, '') : null,
                    'notes' => 'Imported from '.$fileName.' (receipt '.$row['receipt_no'].')',
                    'tax_amount' => 0,
                ]);

                foreach ($row['items'] as $item) {
                    $sale->saleItems()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }

                $sale->recalcTotals(broadcast: false);

                if ($deductStock) {
                    $this->inventory->processSaleInventory(
                        collect($row['items'])->map(fn ($i) => [
                            'product_id' => $i['product_id'],
                            'quantity' => $i['quantity'],
                            'unit_price' => $i['unit_price'],
                        ])->all(),
                        $sale->id,
                        $user,
                        $location,
                        $when,
                    );
                }
            }

            return $import;
        });
    }

    private function normalizeHeader(string $header): string
    {
        $key = Str::snake(Str::lower(trim($header)));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        $key = trim($key, '_');

        return self::HEADER_ALIASES[$key] ?? $key;
    }

    private function normalizePaymentMethod(string $value): ?string
    {
        $value = Str::lower(trim($value));
        $value = match ($value) {
            'ewallet', 'e_wallet', 'e wallet', 'gcash', 'maya', 'wallet' => 'e-wallet',
            default => $value,
        };

        return in_array($value, self::PAYMENT_METHODS, true) ? $value : null;
    }
}
