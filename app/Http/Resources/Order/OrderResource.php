<?php

namespace App\Http\Resources\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->resource->id,
            'user_id'    => $this->resource->user_id,
            'symbol'     => $this->resource->symbol->value,
            'side'       => $this->resource->side->value,
            'price'      => (string) $this->resource->price,
            'amount'     => (string) $this->resource->amount,
            'remaining'  => (string) $this->resource->remaining,
            'status'     => $this->resource->status->value,
            'created_at' => optional($this->resource->created_at)->toISOString(),
            'updated_at' => optional($this->resource->updated_at)->toISOString(),
        ];
    }
}
