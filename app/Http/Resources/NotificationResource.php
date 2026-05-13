<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'payload' => $this->payload,
            'title' => $this->title,
            'message' => $this->message,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_event' => $this->source_event,
            'is_read' => (bool) $this->is_read,
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
        ];
    }
}
