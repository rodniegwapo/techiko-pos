<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
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
            'adjustment_number' => $this->adjustment_number,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayName(),
            'reason' => $this->reason,
            'reason_display' => $this->getReasonDisplayName(),
            'notes' => $this->notes,
            'total_value' => $this->total_value,
            'items_count' => $this->items_count ?? $this->items()->count(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'approved_at' => $this->approved_at,
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'code' => $this->location?->code,
            ],
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'email' => $this->createdBy?->email,
            ],
            'approved_by' => [
                'id' => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
                'email' => $this->approvedBy?->email,
            ],
        ];
    }

    /**
     * Get human-readable status display name
     */
    private function getStatusDisplayName(): string
    {
        $statuses = [
            'draft' => 'Draft',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        return $statuses[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Get human-readable reason display name
     */
    private function getReasonDisplayName(): string
    {
        $reasons = [
            'physical_count' => 'Physical Count',
            'damaged_goods' => 'Damaged Goods',
            'expired_goods' => 'Expired Goods',
            'theft_loss' => 'Theft/Loss',
            'supplier_error' => 'Supplier Error',
            'system_error' => 'System Error',
            'promotion' => 'Promotion',
            'sample' => 'Sample',
            'other' => 'Other',
        ];

        return $reasons[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }
}
