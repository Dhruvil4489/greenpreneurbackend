<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider', 'event_type', 'external_event_id', 'payment_link_id', 'payment_id', 'registration_id',
        'status', 'payload', 'headers', 'processed_at', 'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
    ];
}
