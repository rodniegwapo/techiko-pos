<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\ServiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admins set the plans organizations subscribe to: price per month, AI credits and limits.
 * A new price counts from each organization's next payment (payments already recorded keep theirs).
 * Plans aren't deleted (payments point to them); turn one off instead.
 */
class ServiceTierController extends Controller
{
    /** What a plan can include (Domain::PLAN_FEATURES), as super admins see it. Every plan has the AI assistant. */
    private const FEATURE_LABELS = [
        'sales_history_unlimited' => 'Full sales history and CSV export',
        'multi_location' => 'More than one location',
        'advanced_inventory' => 'Stock movements, valuation and transfers',
        'expenses' => 'Expenses',
        'finance' => 'Finance (statements, P&L, VAT report)',
    ];

    public function index(): Response
    {
        $organizations = Domain::query()
            ->whereNotNull('current_service_tier_id')
            ->selectRaw('current_service_tier_id, COUNT(*) as total')
            ->groupBy('current_service_tier_id')
            ->pluck('total', 'current_service_tier_id');

        return Inertia::render('Plans/Index', [
            'tiers' => ServiceTier::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (ServiceTier $tier) => [
                'id' => $tier->id,
                'slug' => $tier->slug,
                'name' => $tier->name,
                'amount' => (float) $tier->amount,
                'ai_monthly_credits' => $tier->ai_monthly_credits,
                'features' => $tier->features ?? [],
                'max_products' => $tier->max_products,
                'max_users' => $tier->max_users,
                'is_active' => $tier->is_active,
                'shown_on_billing' => $tier->shown_on_billing,
                'sort_order' => $tier->sort_order,
                'organizations' => (int) ($organizations[$tier->id] ?? 0),
            ]),
            'basicSlug' => strtolower((string) config('manual_billing.servicing_basic_tier_slug')),
            'featureOptions' => collect(self::FEATURE_LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'planGating' => (bool) config('features.plan_gating', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $base = Str::slug($data['name']) ?: 'plan';
        $slug = $base;
        for ($i = 2; ServiceTier::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        ServiceTier::query()->create(['features' => [], ...$data, 'slug' => $slug]);

        return back()->with('success', "Plan \"{$data['name']}\" added.");
    }

    public function update(Request $request, ServiceTier $serviceTier): RedirectResponse
    {
        // The slug stays: config (the Basic QR, marketing bullets) is keyed by it.
        $serviceTier->update($this->validated($request));

        return back()->with('success', "Plan \"{$serviceTier->name}\" saved.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            // Left out, a plan keeps what it includes.
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'distinct', Rule::in(Domain::PLAN_FEATURES)],
            'name' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'ai_monthly_credits' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_products' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'shown_on_billing' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        if (isset($data['features'])) {
            $data['features'] = array_values($data['features']);
        }

        return $data;
    }
}
