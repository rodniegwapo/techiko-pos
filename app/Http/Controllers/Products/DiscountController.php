<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountResource;
use App\Models\Product\Discount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $domainSlug = $request->route('domain');

        $data = Discount::query()
            ->when($domainSlug, fn ($q) => $q->forDomain($domainSlug))
            ->when($request->input('domain'), fn ($q, $domain) => $q->where('domain', $domain))
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->paginate();

        return Inertia::render('Discounts/Index', [
            'items' => DiscountResource::collection($data),
            'isGlobalView' => ! $domainSlug,
            'domains' => ! $domainSlug ? \App\Models\Domain::all() : [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        // If called under domains.* route, persist domain slug
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        Discount::create($data);

        return redirect()->back()->with('success', 'Discount created successfully');
    }

    public function update(Request $request, $domain, $discount)
    {
        $discountModel = $this->findInRouteDomain($request, $discount);

        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        $discountModel->update($data);

        return redirect()->back()->with('success', 'Discount updated successfully');
    }

    public function destroy(Request $request, $domain, $discount)
    {
        $discountModel = $this->findInRouteDomain($request, $discount);

        $discountModel->delete();

        return redirect()->back()->with('success', 'Discount deleted successfully');
    }

    /**
     * The discount by ID, limited to the organization in the URL (404 otherwise). Without this, an
     * admin of one organization could edit (and take over) or delete another organization's discount.
     */
    private function findInRouteDomain(Request $request, $discountId): Discount
    {
        return Discount::query()
            ->when($request->route('domain'), fn ($q, $slug) => $q->where('domain', $slug))
            ->findOrFail($discountId);
    }

    private function validatedData(Request $request)
    {
        $isPercentage = in_array($request->input('type'), ['percentage', 'percent'], true);

        $rules = [
            'name' => 'string|max:200|required',
            // 'percent' is the older spelling still used by existing discounts.
            'type' => ['required', 'string', Rule::in(['amount', 'percentage', 'percent'])],
            // A discount can't add to the price, and a percentage can't take off more than 100%.
            'value' => array_filter(['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : null]),
            'min_order_amount' => 'numeric|nullable|min:0',
            'scope' => 'string|in:order,product|required',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|required_with:start_date|after:start_date',
        ];

        // Add domain validation for global view
        if ($request->has('domain') && $request->domain) {
            $rules['domain'] = 'required|string|exists:domains,name_slug';
        }

        $data = $request->validate($rules, [
            'value.max' => 'A percentage discount can\'t be more than 100%.',
        ]);

        if (($data['type'] ?? null) === 'percent') {
            $data['type'] = 'percentage';
        }

        return $data;
    }
}
