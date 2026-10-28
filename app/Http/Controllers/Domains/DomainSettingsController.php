<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use App\Support\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DomainSettingsController extends Controller
{
    /** Currencies an organization can show its money in. */
    public const CURRENCIES = ['PHP', 'USD', 'EUR', 'GBP', 'JPY'];

    public function index(Request $request, Domain $domain)
    {
        $settings = $domain->settings ?? [];

        return Inertia::render('Settings/Index', [
            'account' => [
                'name' => $domain->name,
                'plan' => $domain->currentServiceTier?->name,
                'subscription_ends_at' => $domain->subscription_ends_at?->toDateString(),
                'locations' => InventoryLocation::query()->forDomain($domain->name_slug)->active()->count(),
                'users' => User::query()->where('domain', $domain->name_slug)->count(),
                'created_at' => $domain->created_at?->toDateString(),
            ],
            'currency' => [
                'code' => $domain->currency_code ?: 'PHP',
                'options' => self::CURRENCIES,
            ],
            'salesSettings' => [
                'apply_vat_automatically' => (bool) data_get($settings, 'sales.apply_vat_automatically', false),
                'vat_rate_percent' => (float) data_get($settings, 'sales.vat_rate_percent', 12),
                'vat_pricing_mode' => in_array(data_get($settings, 'sales.vat_pricing_mode'), ['exclusive', 'inclusive'], true)
                    ? data_get($settings, 'sales.vat_pricing_mode')
                    : 'exclusive',
                'allow_overselling' => (bool) data_get($settings, 'sales.allow_overselling', false),
                'hide_out_of_stock' => (bool) data_get($settings, 'sales.hide_out_of_stock', false),
                'allow_new_shift_while_review' => $domain->allowsNewShiftWhileReview(),
                'business_type' => $domain->businessType(),
                'show_all_menu' => $domain->showsAllMenu(),
                'business_type_change' => $domain->businessTypeChange(),
            ],
            // Dine-in suits food businesses; Settings points to it when the plan has it.
            'hasDineIn' => $domain->hasFeature('dine_in'),
            // It changes the menu for everyone and how new products start: owners and admins only.
            'canChangeBusinessType' => FinanceAccess::canSeeAllStores($request->user()),
        ]);
    }

    public function update(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'apply_vat_automatically' => ['sometimes', 'boolean'],
            'vat_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'vat_pricing_mode' => ['sometimes', 'string', 'in:exclusive,inclusive'],
            'allow_overselling' => ['sometimes', 'boolean'],
            'hide_out_of_stock' => ['sometimes', 'boolean'],
            'allow_new_shift_while_review' => ['sometimes', 'boolean'],
            'currency_code' => ['sometimes', 'string', Rule::in(self::CURRENCIES)],
            'business_type' => ['sometimes', 'string', Rule::in(Domain::BUSINESS_TYPES)],
            'show_all_menu' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('currency_code', $validated)) {
            $domain->currency_code = $validated['currency_code'];
        }

        $vatBefore = $domain->salesVatSettings();
        $current = $domain->settings ?? [];
        $sales = $current['sales'] ?? [];

        if (array_key_exists('apply_vat_automatically', $validated)) {
            $sales['apply_vat_automatically'] = (bool) $validated['apply_vat_automatically'];
        }
        if (array_key_exists('vat_rate_percent', $validated)) {
            $sales['vat_rate_percent'] = round((float) $validated['vat_rate_percent'], 2);
        }
        if (array_key_exists('vat_pricing_mode', $validated)) {
            $sales['vat_pricing_mode'] = $validated['vat_pricing_mode'];
        }
        if (array_key_exists('allow_overselling', $validated)) {
            $sales['allow_overselling'] = (bool) $validated['allow_overselling'];
        }
        if (array_key_exists('hide_out_of_stock', $validated)) {
            $sales['hide_out_of_stock'] = (bool) $validated['hide_out_of_stock'];
        }
        if (array_key_exists('allow_new_shift_while_review', $validated)) {
            $sales['allow_new_shift_while_review'] = (bool) $validated['allow_new_shift_while_review'];
        }
        // What the business sells changes the menu for everyone and how new products start, so only an
        // owner or admin changes it, and who did and when is kept. The same values sent back are fine.
        $typeChanged = array_key_exists('business_type', $validated) && ($sales['business_type'] ?? null) !== $validated['business_type'];
        $menuChanged = array_key_exists('show_all_menu', $validated) && $domain->showsAllMenu() !== (bool) $validated['show_all_menu'];
        if (($typeChanged || $menuChanged) && ! FinanceAccess::canSeeAllStores($request->user())) {
            throw ValidationException::withMessages(['business_type' => 'Only an owner or admin can change the business type.']);
        }
        if ($typeChanged) {
            $sales['business_type_previous'] = $sales['business_type'] ?? null;
            $sales['business_type'] = $validated['business_type'];
        }
        if ($menuChanged) {
            $sales['show_all_menu'] = (bool) $validated['show_all_menu'];
        }
        if ($typeChanged || $menuChanged) {
            $sales['business_type_changed_by'] = $request->user()->name;
            $sales['business_type_changed_at'] = now()->toIso8601String();
        }

        $current['sales'] = $sales;
        $domain->update(['settings' => $current]);

        // Open carts store their VAT, so re-total them or they'd keep charging under the old setting.
        if ($domain->salesVatSettings() != $vatBefore) {
            Sale::query()->where('domain', $domain->name_slug)->pending()->get()->each->recalcTotals();
        }

        return redirect()->route('domains.settings.index', ['domain' => $domain])
            ->with('success', 'Settings saved.');
    }
}
