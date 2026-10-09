<?php

namespace App\Models\Finance;

use App\Models\Expense;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repayment: the principal part lowers what is owed; the interest part is a cost, booked as
 * an expense (linked through expense_id) so it reaches the income statement as an other expense.
 */
class LoanPayment extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'principal' => 'decimal:2',
        'interest' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletCashMovement(): BelongsTo
    {
        return $this->belongsTo(WalletCashMovement::class);
    }
}
