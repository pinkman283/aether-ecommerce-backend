<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
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
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->description !== null ? (string) $this->description : null,
            'image' => $this->image !== null ? (string) $this->image : null,
            'icon' => $this->icon !== null ? (string) $this->icon : null,
            'badge' => $this->badge !== null ? (string) $this->badge : null,
            'is_featured' => (bool) $this->is_featured,
            'display_order' => (int) ($this->display_order ?? 0),
            'meta_title' => $this->meta_title !== null ? (string) $this->meta_title : null,
            'meta_description' => $this->meta_description !== null ? (string) $this->meta_description : null,
            'image_alt' => $this->image_alt !== null ? (string) $this->image_alt : null,
            'products_count' => $this->when(isset($this->products_count), (int) $this->products_count),
        ];
    }
}
