<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->product_id,
            'image_url' => (string) $this->image_url,
            'alt_text' => $this->alt_text !== null ? (string) $this->alt_text : null,
            'color_name' => $this->color_name !== null ? (string) $this->color_name : null,
            'is_primary' => (bool) $this->is_primary,
            'display_order' => (int) ($this->display_order ?? 0),
        ];
    }
}
