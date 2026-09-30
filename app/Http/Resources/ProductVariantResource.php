<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Strict public allowlist - NEVER expose cost_price, inventory valuation, or admin metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->product_id,
            'name' => (string) $this->name,
            'size' => $this->size !== null ? (string) $this->size : null,
            'color_name' => $this->color_name !== null ? (string) $this->color_name : null,
            'color_hex' => $this->color_hex !== null ? (string) $this->color_hex : null,
            'sku' => $this->sku !== null ? (string) $this->sku : null,
            'barcode' => $this->barcode !== null ? (string) $this->barcode : null,
            'price_modifier' => (float) ($this->price_modifier ?? 0),
            'stock_quantity' => (int) ($this->stock_quantity ?? 0),
        ];
    }
}
