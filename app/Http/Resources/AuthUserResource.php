<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
    
        $profileUrl = "https://ui-avatars.com/api/?name={$request->user()->name}&background=287e47&color=ffff";

        return array_merge(parent::toArray($request), [
            'email' => $this->email,
            'profileUrl' => $profileUrl,
            'name' => $this->name,
        ]);
    }
}
