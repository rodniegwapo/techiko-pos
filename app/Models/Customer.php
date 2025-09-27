<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'name',
        'phone', 
        'email',
        'loyalty_points',
        'date_of_birth',
        'tier',
        'lifetime_spent',
        'total_purchases',
        'tier_achieved_date'
    ];

    protected $searchable = [
        'name',
        'phone',
        'email'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'tier_achieved_date' => 'date',
        'lifetime_spent' => 'decimal:2'
    ];

    // Relationships
    public function sales()
    {
        return $this->hasMany(Sale::class);
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
        $tier = $this->tier ?? 'bronze';
        
        return [
            'name' => ucfirst($tier),
            'multiplier' => match($tier) {
                'silver' => 1.25,
                'gold' => 1.5,
                'platinum' => 2.0,
                default => 1.0
            },
            'color' => match($tier) {
                'silver' => '#C0C0C0',
                'gold' => '#FFD700',
                'platinum' => '#E5E4E2',
                default => '#CD7F32'
            }
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
        
        // Tier thresholds
        if ($this->lifetime_spent >= 100000) {
            $newTier = 'platinum';
        } elseif ($this->lifetime_spent >= 50000) {
            $newTier = 'gold';
        } elseif ($this->lifetime_spent >= 20000) {
            $newTier = 'silver';
        } else {
            $newTier = 'bronze';
        }

        if ($previousTier !== $newTier) {
            $this->update([
                'tier' => $newTier,
                'tier_achieved_date' => now()
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
}
