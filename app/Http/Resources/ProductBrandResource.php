<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBrandResource extends JsonResource
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
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->description !== null ? (string) $this->description : null,
            'logo' => $this->logo !== null ? (string) $this->logo : null,
            'website' => $this->website !== null ? (string) $this->website : null,
            'is_featured' => (bool) $this->is_featured,
            'display_order' => (int) ($this->display_order ?? 0),
        ];
    }
}
