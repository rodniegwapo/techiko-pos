<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $searchable = [
        'name',
        'phone',
        'email',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'tier_achieved_date' => 'date',
        'lifetime_spent' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'credit_balance' => 'decimal:2',
        'credit_enabled' => 'boolean',
    ];

    // Relationships
    // Remove domain relationship - now using domain string column
    // public function domain()
    // {
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function creditTransactions()
    {
        return $this->hasMany(CreditTransaction::class);
    }

    // Loyalty methods
    public function addPoints(int $points, string $description = 'Purchase reward')
    {
        $this->increment('loyalty_points', $points);

        return $this;
    }

    public function redeemPoints(int $points, string $description = 'Points redemption')
    {
        if ($this->loyalty_points < $points) {
            throw new \Exception('Insufficient loyalty points');
        }

        $this->decrement('loyalty_points', $points);

        return $this;
    }

    public function getTierInfo(): array
    {
        // Get tier from database instead of hardcoded values
        $tierModel = \App\Models\LoyaltyTier::where('name', $this->tier ?? 'bronze')->first();

        if (! $tierModel) {
            // Fallback to bronze if tier not found
            $tierModel = \App\Models\LoyaltyTier::where('name', 'bronze')->first();
        }

        if (! $tierModel) {
            // Ultimate fallback if no tiers exist
            return [
                'id' => null,
                'name' => 'Bronze',
                'multiplier' => 1.0,
                'color' => '#CD7F32',
                'description' => 'Default tier',
                'spending_threshold' => 0,
            ];
        }

        return [
            'id' => $tierModel->id,
            'name' => $tierModel->display_name,
            'multiplier' => $tierModel->multiplier,
            'color' => $tierModel->color,
            'description' => $tierModel->description,
            'spending_threshold' => $tierModel->spending_threshold,
        ];
    }

    public function calculatePointsForPurchase(float $amount): int
    {
        $basePoints = floor($amount / 10); // 1 point per ₱10
        $tierInfo = $this->getTierInfo();

        return (int) ($basePoints * $tierInfo['multiplier']);
    }

    public function updateTierBasedOnSpending(): bool
    {
        $previousTier = $this->tier;

        // Get appropriate tier based on spending from database
        $newTierModel = \App\Models\LoyaltyTier::getTierForSpending($this->lifetime_spent);

        if ($newTierModel && $previousTier !== $newTierModel->name) {
            $this->update([
                'tier' => $newTierModel->name,
                'tier_achieved_date' => now(),
            ]);

            return true; // Tier upgraded
        }

        return false; // No tier change
    }

    public function processLoyaltyForSale(float $saleAmount): array
    {
        $results = [];

        // Calculate and add points
        $pointsEarned = $this->calculatePointsForPurchase($saleAmount);
        if ($pointsEarned > 0) {
            $this->addPoints($pointsEarned);
            $results['points_earned'] = $pointsEarned;
        }

        // Update spending stats
        $this->increment('lifetime_spent', $saleAmount);
        $this->increment('total_purchases');

        // Check for tier upgrade
        $tierUpgraded = $this->updateTierBasedOnSpending();
        if ($tierUpgraded) {
            $results['tier_upgraded'] = true;
            $results['new_tier'] = $this->tier;
        }

        return $results;
    }

    public function scopeFilters($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->search($search))
            ->when($filters['loyalty_status'] ?? null, function ($q, $status) {
                return $status === 'enrolled'
                    ? $q->whereNotNull('loyalty_points')
                    : ($status === 'not_enrolled' ? $q->whereNull('loyalty_points') : $q);
            })
            ->when($filters['tier'] ?? null, fn ($q, $tier) => $q->where('tier', $tier))
            ->when($filters['date_range'] ?? null, function ($q, $range) {
                $now = now();

                return match ($range) {
                    '7_days' => $q->where('created_at', '>=', $now->subDays(7)),
                    '30_days' => $q->where('created_at', '>=', $now->subDays(30)),
                    '3_months' => $q->where('created_at', '>=', $now->subMonths(3)),
                    '1_year' => $q->where('created_at', '>=', $now->subYear()),
                    default => $q,
                };
            });
    }

    public static function defaultLoyaltyData(): array
    {
        return [
            'loyalty_points' => 0,
            'tier' => 'bronze',
            'lifetime_spent' => 0,
            'total_purchases' => 0,
            'tier_achieved_date' => now(),
        ];
    }

    // Credit methods
    public function getAvailableCredit(): float
    {
        return max(0, $this->credit_limit - $this->credit_balance);
    }

    public function canPurchaseOnCredit(float $amount): bool
    {
        if (!$this->credit_enabled) {
            return false;
        }

        return $this->getAvailableCredit() >= $amount;
    }

    public function addCreditTransaction(string $type, float $amount, ?int $saleId = null, ?string $referenceNumber = null, ?string $notes = null, ?\DateTime $dueDate = null): CreditTransaction
    {
        $balanceBefore = $this->credit_balance;

        // Calculate new balance based on transaction type
        // For adjustment, amount can be positive (increase) or negative (decrease)
        $balanceAfter = match ($type) {
            'credit' => $balanceBefore + abs($amount),
            'payment' => max(0, $balanceBefore - abs($amount)),
            'adjustment' => max(0, $balanceBefore + $amount), // Amount can be positive or negative
            'refund' => max(0, $balanceBefore - abs($amount)),
            default => $balanceBefore,
        };

        // Update customer balance
        $this->update(['credit_balance' => $balanceAfter]);

        // Create transaction record
        // For adjustment, store the actual amount (can be negative)
        // For other types, store absolute value
        $transactionAmount = $type === 'adjustment' ? $amount : abs($amount);

        $transaction = CreditTransaction::create([
            'customer_id' => $this->id,
            'sale_id' => $saleId,
            'transaction_type' => $type,
            'amount' => $transactionAmount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'due_date' => $dueDate ?? ($type === 'credit' ? now()->addDays($this->credit_terms_days) : null),
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'user_id' => auth()->id(),
            'domain' => $this->domain ?? null,
        ]);

        return $transaction;
    }

    public function getOverdueTransactions()
    {
        return $this->creditTransactions()
            ->overdue()
            ->orderBy('due_date', 'asc')
            ->get();
    }

    public function getTotalOverdueAmount(): float
    {
        return $this->creditTransactions()
            ->overdue()
            ->sum('amount');
    }

    public function getCreditHistory(int $limit = 50)
    {
        return $this->creditTransactions()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
