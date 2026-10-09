<?php

namespace App\Http\Resources;

use App\Models\SalePayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Sales History list.
 *
 * @mixin \App\Models\Sale
 */
class SalesHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = $this->transaction_date
            ? Carbon::parse($this->transaction_date)->timezone(config('app.timezone', 'UTC'))
            : null;

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'transaction_date' => $date?->toIso8601String(),
            'transaction_date_display' => $date?->format('Y-m-d h:i A'),
            'cashier_name' => $this->user?->name,
            'customer_name' => $this->customer?->name ?? 'Walk-in',
            'location_name' => $this->location?->name,
            'payment_method' => $this->payment_method,
            // For a sale paid in parts, the parts in one line ("Cash 200.00 + GCash 300.00").
            'payment_card_type' => $this->payment_method === 'split' && $this->relationLoaded('payments')
                ? SalePayment::breakdown($this->payments)
                : $this->paymentCardType?->name,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map->toDisplayArray()->all()),
            'payment_reference' => $this->payment_reference,
            'payment_status' => $this->payment_status,
            'is_credit_sale' => (bool) $this->is_credit_sale,
            'total_amount' => round((float) $this->total_amount, 2),
            'discount_amount' => round((float) $this->discount_amount, 2),
            'tax_amount' => round((float) $this->tax_amount, 2),
            'grand_total' => round((float) $this->grand_total, 2),
            'items_count' => (int) ($this->items_count ?? 0),
            'voided_items_count' => (int) ($this->voided_items_count ?? 0),
        ];
    }
}
