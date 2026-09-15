<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['movement_type_display'] = $this->movement_type_display;

        // Only who made the movement; the rest of their account isn't needed on these pages.
        if ($this->relationLoaded('user')) {
            $data['user'] = $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null;
        }

        return $data;
    }
}
