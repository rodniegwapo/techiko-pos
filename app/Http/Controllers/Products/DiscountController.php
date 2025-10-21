<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountResource;
use App\Models\Product\Discount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $domainSlug = $request->route('domain');

        $data = Discount::query()
            ->when($domainSlug, fn($q) => $q->forDomain($domainSlug))
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->paginate();

        return Inertia::render('Discounts/Index', [
            'items' => DiscountResource::collection($data),
            'isGlobalView' => !$domainSlug,
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

        return redirect()->back();
    }

    public function update(Request $request, $domain, $discount)
    {
        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        // Manually resolve the discount model
        $discountModel = Discount::find($discount);
        if (!$discountModel) {
            return redirect()->back()->with('error', 'Discount not found');
        }

        $discountModel->update($data);

        return redirect()->back()->with('success', 'Discount updated successfully');
    }

    public function destroy(Request $request, $domain, $discount)
    {
        logger('DiscountController destroy called');
        logger('Request route parameters:', $request->route()->parameters());
        logger('Domain parameter:', ['domain' => $domain]);
        logger('Discount parameter type:', ['type' => gettype($discount)]);
        logger('Discount parameter value:', ['value' => $discount]);
        
        // Manually resolve the discount model
        $discountModel = Discount::find($discount);
        if (!$discountModel) {
            return redirect()->back()->with('error', 'Discount not found');
        }
        
        $discountModel->delete();

        return redirect()->back()->with('success', 'Discount deleted successfully');
    }

    private function validatedData(Request $request)
    {
        return $request->validate([
            'name' => 'string|max:200|required',
            'type' => 'string|in:amount,percentage|required',
            'value' => 'numeric|required',
            'min_order_amount' => 'numeric|nullable',
            'scope' => 'string|in:order,product|required',
            'start_date' => 'date|required',
            'end_date' => 'date|required|after:start_date'
        ]);
    }
}
