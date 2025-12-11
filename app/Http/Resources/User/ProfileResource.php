<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->resource->id,
            'name'  => (string) $this->resource->name,
            'email' => (string) $this->resource->email,
            // Ensure balance is returned as a string to avoid float precision issues in JS
            'balance' => (string) $this->resource->balance,
            'assets'  => [],
        ];
    }
}
