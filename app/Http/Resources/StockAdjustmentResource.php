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
        $data = parent::toArray($request);

        // Readable labels, so the pages don't have to repeat the enum maps.
        $data['reason_display'] = $this->reason_display;
        $data['status_display'] = $this->status_display;

        // Only who created and approved it; the rest of their accounts isn't needed on these pages.
        foreach (['created_by' => 'createdBy', 'approved_by' => 'approvedBy'] as $key => $relation) {
            if ($this->relationLoaded($relation)) {
                $user = $this->{$relation};
                $data[$key] = $user ? ['id' => $user->id, 'name' => $user->name] : null;
            }
        }

        return $data;
    }
}
