<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Points per currency unit (redemption value)
    |--------------------------------------------------------------------------
    |
    | Customer redeems points for a discount: discount_peso = points / this value.
    | Example: 100 means 100 loyalty points = ₱1.00 off before caps.
    |
    */
    'points_per_currency_unit' => (float) env('LOYALTY_POINTS_PER_CURRENCY_UNIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Maximum percent of eligible net (after order discounts)
    |--------------------------------------------------------------------------
    |
    | Loyalty discount cannot exceed this fraction of (total_amount − discount_amount)
    | before VAT. Example: 50 = at most half the subtotal can be paid with points.
    |
    */
    'max_redemption_percent_of_eligible_net' => (float) env('LOYALTY_MAX_REDEMPTION_PERCENT', 50),

    /*
    |--------------------------------------------------------------------------
    | Minimum points per redemption
    |--------------------------------------------------------------------------
    |
    | Ignored when redemption is cleared (0 points).
    |
    */
    'min_points_redemption' => (int) env('LOYALTY_MIN_POINTS_REDEMPTION', 1),

];
