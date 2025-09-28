<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tierInfo = $this->getTierInfo();
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'date_of_birth' => $this->date_of_birth,
            'loyalty_points' => $this->loyalty_points,
            'tier' => $this->tier ?? 'bronze',
            'tier_info' => $tierInfo,
            'lifetime_spent' => $this->lifetime_spent ?? 0,
            'total_purchases' => $this->total_purchases ?? 0,
            'tier_achieved_date' => $this->tier_achieved_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'display_text' => $this->name . ($this->phone ? " ({$this->phone})" : ''),
            'is_loyalty_member' => !is_null($this->loyalty_points),
            'recent_purchases' => $this->whenLoaded('sales', function () {
                return $this->sales->take(5)->map(function ($sale) {
                    return [
                        'id' => $sale->id,
                        'grand_total' => $sale->grand_total,
                        'transaction_date' => $sale->transaction_date,
                        'created_at' => $sale->created_at,
                    ];
                });
            }),
        ];
    }
}
