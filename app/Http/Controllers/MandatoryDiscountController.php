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
        $domainSlug = $request->route('domain');

        $data = MandatoryDiscount::query()
            ->when($domainSlug, fn($q) => $q->where('domain', $domainSlug))
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->paginate();

        return Inertia::render('MandatoryDiscounts/Index', [
            'items' => MandatoryDiscountResource::collection($data),
            'isGlobalView' => !$domainSlug,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        MandatoryDiscount::create($data);

        return redirect()->back();
    }

    public function update(Request $request, $domain, $mandatoryDiscount)
    {
        $data = $this->validatedData($request);
        if ($slug = $request->route('domain')) {
            $data['domain'] = $slug;
        }

        // Manually resolve the mandatory discount model
        $mandatoryDiscountModel = MandatoryDiscount::find($mandatoryDiscount);
        if (!$mandatoryDiscountModel) {
            return redirect()->back()->with('error', 'Mandatory discount not found');
        }

        $mandatoryDiscountModel->update($data);

        return redirect()->back()->with('success', 'Mandatory discount updated successfully');
    }

    public function destroy(Request $request, $domain, $mandatoryDiscount)
    {
        // Manually resolve the mandatory discount model
        $mandatoryDiscountModel = MandatoryDiscount::find($mandatoryDiscount);
        if (!$mandatoryDiscountModel) {
            return redirect()->back()->with('error', 'Mandatory discount not found');
        }

        $mandatoryDiscountModel->delete();

        return redirect()->back()->with('success', 'Mandatory discount deleted successfully');
    }

    private function validatedData(Request $request)
    {
        return $request->validate([
            'name' => 'string|max:200|required',
            'type' => 'string|in:amount,percentage|required',
            'value' => 'numeric|required|min:0',
            'is_active' => 'boolean'
        ]);
    }
}
