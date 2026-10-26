<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\SaleImport;
use App\Services\Sales\SaleImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bring in past sales from a CSV: download the template, upload a filled copy, review what will
 * happen, then confirm. Managers and above only.
 */
class SaleImportController extends Controller
{
    private const PREVIEW_MINUTES = 60;

    public function __construct(private SaleImportService $imports) {}

    public function index(Request $request, Domain $domain)
    {
        $this->authorizeImport($request);

        return Inertia::render('SalesImport/Index', [
            'locations' => $this->sellableLocations($domain)->get(['id', 'name']),
            'history' => SaleImport::query()
                ->where('domain', $domain->name_slug)
                ->with('user:id,name', 'location:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (SaleImport $i) => [
                    'id' => $i->id,
                    'file_name' => $i->file_name,
                    'sales_count' => $i->sales_count,
                    'location' => $i->location?->name,
                    'user' => $i->user?->name,
                    'deduct_stock' => (bool) ($i->options['deduct_stock'] ?? false),
                    'created_at' => $i->created_at?->toDateTimeString(),
                ]),
            'maxRows' => SaleImportService::MAX_ROWS,
            'domainName' => $domain->name,
        ]);
    }

    public function template(Request $request, Domain $domain): StreamedResponse
    {
        $this->authorizeImport($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            foreach (SaleImportService::templateRows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'sales-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Checks the uploaded file and keeps the result for the confirm step. Nothing is saved yet. */
    public function preview(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')],
        ]);

        $location = $this->sellableLocations($domain)->whereKey($data['location_id'])->first();
        if (! $location) {
            return response()->json(['message' => 'Pick a store that sells to customers.'], 422);
        }

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $previous = SaleImport::query()->where('domain', $domain->name_slug)->where('file_hash', $hash)->first();
        if ($previous) {
            return response()->json([
                'message' => 'This exact file was already imported on '.$previous->created_at->format('M j, Y g:i A').'. Importing it again would double your sales.',
            ], 422);
        }

        $parsed = $this->imports->parse($file->getRealPath());
        if ($parsed['rows'] === []) {
            return response()->json(['message' => $parsed['errors'][0]['message'] ?? 'No rows found.'], 422);
        }

        $result = $this->imports->validate($parsed['rows'], $domain->name_slug, $location->id);
        $errors = array_merge($parsed['errors'], $result['errors']);
        usort($errors, fn ($a, $b) => $a['line'] <=> $b['line']);

        $token = null;
        if ($result['sales'] !== []) {
            $token = (string) Str::uuid();
            Cache::put($this->cacheKey($domain, $request, $token), [
                'sales' => $result['sales'],
                'file_name' => $file->getClientOriginalName(),
                'file_hash' => $hash,
                'rows_count' => count($parsed['rows']),
                'location_id' => $location->id,
            ], now()->addMinutes(self::PREVIEW_MINUTES));
        }

        return response()->json([
            'token' => $token,
            'file_name' => $file->getClientOriginalName(),
            'rows_count' => count($parsed['rows']),
            'sales_count' => count($result['sales']),
            'total' => $result['total'],
            'errors' => array_slice($errors, 0, 200),
            'errors_count' => count($errors),
            'sales' => collect($result['sales'])->take(50)->map(fn ($s) => [
                'receipt_no' => $s['receipt_no'],
                'date' => $s['date'],
                'payment_method' => $s['payment_method'],
                'items' => count($s['items']),
                'total' => $s['total'],
            ])->values(),
        ]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'deduct_stock' => ['sometimes', 'boolean'],
        ]);

        $key = $this->cacheKey($domain, $request, $data['token']);
        $staged = Cache::get($key);
        if (! $staged) {
            return response()->json(['message' => 'This check expired. Upload the file again.'], 422);
        }

        $location = $this->sellableLocations($domain)->whereKey($staged['location_id'])->first();
        if (! $location) {
            return response()->json(['message' => 'That store is no longer available.'], 422);
        }

        try {
            $import = $this->imports->commit(
                $staged['sales'],
                $domain->name_slug,
                $location,
                $request->user(),
                $staged['file_name'],
                $staged['file_hash'],
                $staged['rows_count'],
                ['deduct_stock' => (bool) ($data['deduct_stock'] ?? false)],
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['message' => 'This file was already imported.'], 422);
        }

        Cache::forget($key);

        return response()->json([
            'import_id' => $import->id,
            'sales_count' => $import->sales_count,
        ]);
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()?->isManagerOrAbove() || $request->user()?->isSuperUser(), 403, 'Only managers and owners can import sales.');
    }

    private function sellableLocations(Domain $domain)
    {
        return InventoryLocation::query()
            ->forDomain($domain->name_slug)
            ->active()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->orderBy('name');
    }

    private function cacheKey(Domain $domain, Request $request, string $token): string
    {
        return "sale-import:{$domain->name_slug}:{$request->user()->id}:{$token}";
    }
}
