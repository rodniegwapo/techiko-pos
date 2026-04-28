<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class VatReportCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, location: InventoryLocation, user: User}
     */
    private function seedDomainContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'VAT Test Org',
            'name_slug' => 'vat-org-'.Str::lower(Str::random(8)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);

        return compact('domain', 'location', 'user');
    }

    public function test_vat_export_csv_streams_rows_matching_filters(): void
    {
        Config::set('vat_report.max_export_rows', 50000);

        $ctx = $this->seedDomainContext();
        $domain = $ctx['domain'];
        $location = $ctx['location'];
        $user = $ctx['user'];

        $start = now()->startOfMonth()->startOfDay();
        $mid = now()->copy()->day(15)->setTime(14, 30, 0);

        Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-VAT-A-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $location->id,
            'total_amount' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 12.00,
            'grand_total' => 112.00,
            'transaction_date' => $mid->copy()->subDays(3),
        ]);

        Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-VAT-B-'.Str::random(6),
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'location_id' => $location->id,
            'total_amount' => 50.00,
            'discount_amount' => 0,
            'tax_amount' => 6.00,
            'grand_total' => 56.00,
            'transaction_date' => $mid,
        ]);

        self::assertSame(2, Sale::query()->where('domain', $domain->name_slug)->where('payment_status', 'paid')->count());

        $url = route('domains.vat-report.export', [
            'domain' => $domain->name_slug,
            'start_date' => $start->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'location_id' => $location->id,
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($raw)), fn ($l) => $l !== ''));

        self::assertCount(3, $lines);

        self::assertStringContainsString('transaction_date_iso', $lines[0]);
        self::assertStringContainsString('tax_amount', $lines[0]);

        self::assertStringContainsString('112', $lines[1].$lines[2]);
        self::assertStringContainsString('Walk-in', $lines[1]);
    }

    public function test_vat_export_csv_returns_422_when_row_count_exceeds_cap(): void
    {
        Config::set('vat_report.max_export_rows', 1);

        $ctx = $this->seedDomainContext();
        $domain = $ctx['domain'];
        $location = $ctx['location'];
        $user = $ctx['user'];

        Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-CAP-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $location->id,
            'total_amount' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 12.00,
            'grand_total' => 112.00,
            'transaction_date' => now(),
        ]);

        Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-CAP-B-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $location->id,
            'total_amount' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 12.00,
            'grand_total' => 112.00,
            'transaction_date' => now(),
        ]);

        $url = route('domains.vat-report.export', [
            'domain' => $domain->name_slug,
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($m) => is_string($m) && str_contains($m, 'maximum of 1'));
    }

    public function test_vat_export_json_returns_transactions_and_summary(): void
    {
        Config::set('vat_report.max_export_rows', 50000);

        $ctx = $this->seedDomainContext();
        $domain = $ctx['domain'];
        $location = $ctx['location'];
        $user = $ctx['user'];

        $start = now()->startOfMonth()->startOfDay();
        $mid = now()->copy()->day(15)->setTime(10, 0, 0);

        Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-JSON-A-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $location->id,
            'total_amount' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 12.00,
            'grand_total' => 112.00,
            'transaction_date' => $mid,
        ]);

        $url = route('domains.vat-report.export-json', [
            'domain' => $domain->name_slug,
            'start_date' => $start->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertOk();
        $response->assertJsonPath('domain.name', $domain->name);
        $response->assertJsonPath('domain.name_slug', $domain->name_slug);
        $response->assertJsonPath('summary.sales_count', 1);
        $response->assertJsonPath('transactions.0.grand_total', 112);
        $response->assertJsonPath('transactions.0.tax_amount', 12);
        $response->assertJsonPath('filters.start_date', $start->toDateString());
    }

    public function test_vat_export_json_returns_422_when_row_count_exceeds_cap(): void
    {
        Config::set('vat_report.max_export_rows', 1);

        $ctx = $this->seedDomainContext();
        $domain = $ctx['domain'];
        $location = $ctx['location'];
        $user = $ctx['user'];

        foreach (['X', 'Y'] as $suffix) {
            Sale::query()->create([
                'domain' => $domain->name_slug,
                'user_id' => $user->id,
                'invoice_number' => 'INV-JSON-CAP-'.$suffix.'-'.Str::random(4),
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'location_id' => $location->id,
                'total_amount' => 100.00,
                'discount_amount' => 0,
                'tax_amount' => 12.00,
                'grand_total' => 112.00,
                'transaction_date' => now(),
            ]);
        }

        $url = route('domains.vat-report.export-json', [
            'domain' => $domain->name_slug,
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($m) => is_string($m) && str_contains($m, 'maximum of 1'));
    }
}
