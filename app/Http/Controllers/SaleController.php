<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Jobs\SyncSaleDraft;
use App\Models\Category;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Sales/Index', [
            'categories' => Category::all()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sale $sale)
    {
        //
    }

    public function products(Request $request)
    {
        $product = Product::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('category'), function ($query, $category) {
                return $query->whereHas('category', function ($query) use ($category) {
                    return $query->where('name', $category);
                });
            })->with('category')->get();


        return ProductResource::collection($product);
    }

    public function storeDraft(Request $request)
    {
        $order = Sale::create([
            'user_id' => $request->user()->id,
            'payment_status' => 'pending',
            'invoice_number' => Str::random(10),
            'transaction_date' => now()
        ]);

        return response()->json(['order' => $order]);
    }

    public function syncDraft(Request $request, Sale $sale)
    {
        SyncSaleDraft::dispatch($sale, $request->items);

        return response()->json(['message' => 'Sale draft is syncing...']);
    }

    public function voidItem(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'pin_code'   => 'required|string',
            'reason'     => 'nullable|string',
            'product_id' => 'required|integer',
        ]);

        $currentUser = auth()->user();

        // If current user is Manager → use their own PIN
        if ($currentUser->hasRole('manager') || $currentUser->hasRole('admin')) {
            $userPin = UserPin::where('user_id', $currentUser->id)->first();

            if (! $userPin || ! Hash::check($validated['pin_code'], $userPin->pin_code)) {
                throw ValidationException::withMessages([
                    'pin_code' => ['The provided Pin Code is incorrect.'],
                ]);
            }

            $approvedBy = $currentUser->id; // same person approved
        }

        // If current user is Cashier → must use ANY manager’s pin
        else if ($currentUser->hasRole('cashier')) {
            logger('casddjss');
            $managerPins = UserPin::whereHas('user.roles', function ($q) {
                $q->where('name', 'Manager');
            })->get();

            $approvedBy = null;

            foreach ($managerPins as $managerPin) {
                if (Hash::check($validated['pin_code'], $managerPin->pin_code)) {
                    $approvedBy = $managerPin->user_id; // store which manager approved
                    break;
                }
            }

            if (! $approvedBy) {
                throw ValidationException::withMessages([
                    'pin_code' => ['The provided Manager Pin Code is incorrect.'],
                ]);
            }
        }

        // Find sale item
        $saleItem = $sale->saleItems()
            ->where('product_id', $validated['product_id'])
            ->firstOrFail();

        // // Mark as voided
        // $saleItem->update([
        //     'voided_by'   => $currentUser->id,   // cashier (who initiated)
        //     'approved_by' => $approvedBy,        // manager (who approved)
        //     'void_reason' => $validated['reason'],
        // ]);

        logger('sale items');
        logger($saleItem);
        return response()->json([
            'message' => 'Sale item voided successfully.',
            'item'    => $saleItem,
        ]);
    }
}
