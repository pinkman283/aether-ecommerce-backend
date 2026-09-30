<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
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
            'user_name' => (string) $this->user_name,
            'user_avatar' => $this->user_avatar !== null ? (string) $this->user_avatar : null,
            'rating' => (int) $this->rating,
            'title' => $this->title !== null ? (string) $this->title : null,
            'comment' => $this->comment !== null ? (string) $this->comment : null,
            'is_verified_purchase' => (bool) $this->is_verified_purchase,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
        ];
    }
}
