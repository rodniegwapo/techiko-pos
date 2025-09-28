<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyTierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'multiplier' => $this->multiplier,
            'spending_threshold' => $this->spending_threshold,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'customer_count' => $this->when(
                $request->routeIs('loyalty-tiers.index'),
                function () {
                    return \App\Models\Customer::where('tier', $this->name)->count();
                }
            ),
            'formatted_threshold' => '$' . number_format($this->spending_threshold, 2),
            'formatted_multiplier' => $this->multiplier . 'x',
        ];
    }
}
