<?php

namespace App\Http\Controllers;

use App\Http\Resources\MandatoryDiscountResource;
use App\Models\MandatoryDiscount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MandatoryDiscountController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureGlobalAccess($request);
        $domainSlug = $request->route('domain');

        $data = MandatoryDiscount::query()
            ->when($domainSlug, fn($q) => $q->where('domain', $domainSlug))
            ->when($request->input('domain'), fn($q, $domain) => $q->where('domain', $domain))
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->paginate();

        return Inertia::render('MandatoryDiscounts/Index', [
            'items' => MandatoryDiscountResource::collection($data),
            'isGlobalView' => !$domainSlug,
            'domains' => !$domainSlug ? \App\Models\Domain::all() : [],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureGlobalAccess($request);
        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        MandatoryDiscount::create($data);

        return redirect()->back()->with('success', 'Mandatory discount created successfully');
    }

    public function update(Request $request)
    {
        $mandatoryDiscountModel = $this->findInRouteDomain($request);

        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        $mandatoryDiscountModel->update($data);

        return redirect()->back()->with('success', 'Mandatory discount updated successfully');
    }

    public function destroy(Request $request)
    {
        $this->findInRouteDomain($request)->delete();

        return redirect()->back()->with('success', 'Mandatory discount deleted successfully');
    }

    /**
     * The routes outside an organization (/mandatory-discounts) cover every organization, so only
     * super users may use them. Otherwise an organization admin could list, create, edit or delete
     * other organizations' mandatory discounts there.
     */
    private function ensureGlobalAccess(Request $request): void
    {
        if (! $request->route('domain') && ! $request->user()?->isSuperUser()) {
            abort(403);
        }
    }

    /**
     * The mandatory discount from the URL, limited to the URL's organization (404 otherwise).
     */
    private function findInRouteDomain(Request $request): MandatoryDiscount
    {
        $this->ensureGlobalAccess($request);

        return MandatoryDiscount::query()
            ->when($request->route('domain'), fn ($q, $slug) => $q->where('domain', $slug))
            ->findOrFail($request->route('mandatory_discount'));
    }

    private function validatedData(Request $request)
    {
        $rules = [
            'name' => 'string|max:200|required',
            'type' => 'string|in:amount,percentage|required',
            // A discount can't add to the price, a percentage can't take off more than 100%, and
            // the column holds at most 99,999,999.99.
            'value' => array_filter([
                'required',
                'numeric',
                'min:0',
                $request->input('type') === 'percentage' ? 'max:100' : 'max:99999999.99',
            ]),
            'is_active' => 'boolean'
        ];

        // Add domain validation for global view
        if ($request->has('domain') && $request->domain) {
            $rules['domain'] = 'required|string|exists:domains,name_slug';
        }

        return $request->validate($rules, [
            'value.max' => $request->input('type') === 'percentage'
                ? 'A percentage discount can\'t be more than 100%.'
                : 'The value can\'t be more than 99,999,999.99.',
        ]);
    }
}
