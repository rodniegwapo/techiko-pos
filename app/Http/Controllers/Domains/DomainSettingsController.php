<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DomainSettingsController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $settings = $domain->settings ?? [];

        return Inertia::render('Settings/Index', [
            'salesSettings' => [
                'apply_vat_automatically' => (bool) data_get($settings, 'sales.apply_vat_automatically', false),
                'vat_rate_percent' => (float) data_get($settings, 'sales.vat_rate_percent', 12),
                'vat_pricing_mode' => in_array(data_get($settings, 'sales.vat_pricing_mode'), ['exclusive', 'inclusive'], true)
                    ? data_get($settings, 'sales.vat_pricing_mode')
                    : 'exclusive',
                'allow_overselling' => (bool) data_get($settings, 'sales.allow_overselling', true),
            ],
        ]);
    }

    public function update(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'apply_vat_automatically' => ['sometimes', 'boolean'],
            'vat_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'vat_pricing_mode' => ['sometimes', 'string', 'in:exclusive,inclusive'],
            'allow_overselling' => ['sometimes', 'boolean'],
        ]);

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

        $current['sales'] = $sales;
        $domain->update(['settings' => $current]);

        return redirect()->route('domains.settings.index', ['domain' => $domain])
            ->with('success', 'Settings saved.');
    }
}
