<?php

namespace App\Services;

use App\Jobs\SyncSaleDraft;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserPin;
use App\Models\VoidLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class SaleService
{
    public function storeDraft($user)
    {
        return Sale::create([
            'user_id' => $user->id,
            'payment_status' => 'pending',
            'invoice_number' => Str::random(10),
            'transaction_date' => now()
        ]);
    }

    public function syncDraft(Sale $sale, array $items)
    {
        SyncSaleDraft::dispatch($sale, $items);
    }

   public function voidItem(Sale $sale, array $validated, $currentUser)
    {
        $saleItem = $sale->saleItems()
            ->where('product_id', $validated['product_id'])
            ->firstOrFail();

        // Check PIN and get approver
        $approvedBy = $this->validatePin($currentUser, $validated['pin_code']);

        // Create log
        VoidLog::create([
            'sale_item_id' => $saleItem->id,
            'user_id'      => $currentUser->id,
            'approver_id'  => $approvedBy,
            'reason'       => $validated['reason'] ?? null,
            'amount'       => $saleItem->unit_price,
        ]);

        // Soft delete
        $saleItem->delete();

        return $saleItem;
    }

    private function validatePin($currentUser, string $pinCode): int
    {
        if ($currentUser->hasAnyRole(['manager', 'admin'])) {
            return $this->validateManagerPin($currentUser->id, $pinCode);
        }

        if ($currentUser->hasRole('cashier')) {
            return $this->validateCashierPin($pinCode);
        }

        throw ValidationException::withMessages([
            'pin_code' => ['You are not authorized to void items.'],
        ]);
    }

    private function validateManagerPin(int $userId, string $pinCode): int
    {
        $userPin = UserPin::where('user_id', $userId)->first();

        if (! $userPin || ! Hash::check($pinCode, $userPin->pin_code)) {
            throw ValidationException::withMessages([
                'pin_code' => ['The provided Pin Code is incorrect.'],
            ]);
        }

        return $userId;
    }

    private function validateCashierPin(string $pinCode): int
    {
        $managerPin = UserPin::whereHas('user.roles', function ($q) {
            $q->where('name', 'manager');
        })->get()
          ->first(fn($pin) => Hash::check($pinCode, $pin->pin_code));

        if (! $managerPin) {
            throw ValidationException::withMessages([
                'pin_code' => ['The provided Pin Code is incorrect.'],
            ]);
        }

        return $managerPin->user_id;
    }
}
