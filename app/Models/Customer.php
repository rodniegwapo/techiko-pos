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
        'address',
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
        // Get tier from database instead of hardcoded values
        $tierModel = \App\Models\LoyaltyTier::where('name', $this->tier ?? 'bronze')->first();
        
        if (!$tierModel) {
            // Fallback to bronze if tier not found
            $tierModel = \App\Models\LoyaltyTier::where('name', 'bronze')->first();
        }
        
        if (!$tierModel) {
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
