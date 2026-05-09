<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Sale;
use Illuminate\Validation\ValidationException;

class LoyaltyRedemptionService
{
    /**
     * Net subtotal after order-level discounts only (before loyalty redemption and VAT).
     */
    public function eligibleNetBeforeLoyalty(Sale $sale): float
    {
        return max(0, (float) $sale->total_amount - (float) $sale->discount_amount);
    }

    /**
     * @return array{points: int, peso_discount: float}
     */
    public function computeRedemption(Customer $customer, Sale $sale, int $requestedPoints): array
    {
        if ($requestedPoints <= 0) {
            return ['points' => 0, 'peso_discount' => 0.0];
        }

        $pointsPerCurrency = max(1.0, (float) config('loyalty.points_per_currency_unit', 100));
        $maxPercent = max(0.0, min(100.0, (float) config('loyalty.max_redemption_percent_of_eligible_net', 50)));
        $minPoints = max(1, (int) config('loyalty.min_points_redemption', 1));

        if ($requestedPoints < $minPoints) {
            throw ValidationException::withMessages([
                'loyalty_points' => __('You must redeem at least :min points.', ['min' => $minPoints]),
            ]);
        }

        $eligibleNet = $this->eligibleNetBeforeLoyalty($sale);
        if ($eligibleNet <= 0) {
            throw ValidationException::withMessages([
                'loyalty_points' => __('There is no eligible amount to apply loyalty redemption to.'),
            ]);
        }

        $maxPesoByPercent = round($eligibleNet * ($maxPercent / 100), 2);
        $maxPointsByCap = (int) floor($maxPesoByPercent * $pointsPerCurrency);
        $maxPointsByEligible = (int) floor($eligibleNet * $pointsPerCurrency);

        $effectivePoints = min($requestedPoints, $customer->loyalty_points, $maxPointsByCap, $maxPointsByEligible);

        if ($effectivePoints < $minPoints) {
            throw ValidationException::withMessages([
                'loyalty_points' => __('Insufficient loyalty points or the requested amount exceeds the maximum allowed for this order.'),
            ]);
        }

        $pesoDiscount = round(min(
            $effectivePoints / $pointsPerCurrency,
            $eligibleNet,
            $maxPesoByPercent
        ), 2);

        return ['points' => $effectivePoints, 'peso_discount' => $pesoDiscount];
    }

    /**
     * Store planned redemption on a pending sale (does not debit customer loyalty balance).
     */
    public function syncPendingRedemption(Domain $domain, Sale $sale, ?Customer $customer, int $requestedPoints): void
    {
        if ($sale->payment_status !== 'pending') {
            throw ValidationException::withMessages([
                'sale' => __('Only pending sales can use loyalty redemption.'),
            ]);
        }

        if ($requestedPoints === 0) {
            $sale->update([
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_amount' => 0,
            ]);
            $sale->recalcTotals();

            return;
        }

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => __('Select a customer to redeem loyalty points.'),
            ]);
        }

        if ($sale->domain && $sale->domain !== $domain->name_slug) {
            throw ValidationException::withMessages([
                'sale' => __('Sale does not belong to this organization.'),
            ]);
        }

        if ($customer->domain !== $domain->name_slug) {
            throw ValidationException::withMessages([
                'customer_id' => __('Customer does not belong to this organization.'),
            ]);
        }

        $sale->refresh();
        $this->computeRedemptionTuple($customer, $sale, $requestedPoints);
    }

    /**
     * Used at checkout inside the payment transaction before debiting balance.
     *
     * @return array{points: int, peso_discount: float}
     */
    public function applyPendingForCheckout(Sale $sale, Customer $customer, int $requestedPoints): array
    {
        if ($sale->payment_status !== 'pending') {
            throw ValidationException::withMessages([
                'sale' => __('Only pending sales can use loyalty redemption.'),
            ]);
        }

        if ($requestedPoints <= 0) {
            $sale->update([
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_amount' => 0,
            ]);
            $sale->recalcTotals();

            return ['points' => 0, 'peso_discount' => 0.0];
        }

        $sale->refresh();

        return $this->computeRedemptionTuple($customer, $sale, $requestedPoints);
    }

    /**
     * @return array{points: int, peso_discount: float}
     */
    private function computeRedemptionTuple(Customer $customer, Sale $sale, int $requestedPoints): array
    {
        $result = $this->computeRedemption($customer, $sale, $requestedPoints);

        $sale->update([
            'loyalty_points_redeemed' => $result['points'],
            'loyalty_discount_amount' => $result['peso_discount'],
        ]);
        $sale->recalcTotals();

        return $result;
    }
}
