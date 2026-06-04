<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventQrScanLog extends Model
{
    use HasUuids;

    protected $table = 'event_qr_scan_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'user_id',
        'scanner_id',
        'qr_token',
        'scan_status',
        'scan_message',
        'scanned_at',
        'device_info',
        'meta',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'device_info' => 'array',
        'meta' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(ScanAppUser::class, 'scanner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
