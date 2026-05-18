<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventOccurrence extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'occurrence_date',
        'start_at',
        'end_at',
        'status',
        'sequence',
        'registration_limit',
        'registered_count',
        'checked_in_count',
        'metadata',
    ];

    protected $casts = [
        'occurrence_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'sequence' => 'integer',
        'registration_limit' => 'integer',
        'registered_count' => 'integer',
        'checked_in_count' => 'integer',
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'occurrence_id');
    }
}
