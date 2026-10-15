<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\ServiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admins set the plans organizations subscribe to: price per month, AI credits and limits.
 * A new price counts from each organization's next payment (payments already recorded keep theirs).
 * Plans aren't deleted (payments point to them); turn one off instead.
 */
class ServiceTierController extends Controller
{
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
                'max_products' => $tier->max_products,
                'max_users' => $tier->max_users,
                'is_active' => $tier->is_active,
                'shown_on_billing' => $tier->shown_on_billing,
                'sort_order' => $tier->sort_order,
                'organizations' => (int) ($organizations[$tier->id] ?? 0),
            ]),
            'basicSlug' => strtolower((string) config('manual_billing.servicing_basic_tier_slug')),
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

        ServiceTier::query()->create([...$data, 'slug' => $slug]);

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
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'ai_monthly_credits' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_products' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'shown_on_billing' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
