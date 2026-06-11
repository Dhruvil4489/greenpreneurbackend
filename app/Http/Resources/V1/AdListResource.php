<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AdListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'redirect_url' => $this->redirect_url,
            'button_text' => $this->button_text,
            'page_name' => $this->page_name,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
