<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'circle_id',
        'created_by_user_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'is_virtual',
        'location_text',
        'agenda',
        'speakers',
        'banner_url',
        'visibility',
        'is_paid',
        'metadata',
        'district_id',
        'event_type',
        'event_category',
        'mode',
        'organizer_user_id',
        'registration_limit',
        'ticket_price',
        'revenue_target',
        'total_revenue',
        'total_expenses',
        'net_pnl',
        'qr_checkin_enabled',
        'is_public',
        'sponsor_count',
        'feedback_sent',
        'gallery_published',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_day_of_week',
        'recurrence_week_of_month',
        'recurrence_day_of_month',
        'recurrence_month',
        'visitor_registration_enabled',
        'member_registration_enabled',
        'online_meeting_url',
        'zoho_form_url',
        'recurrence_ends_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_virtual' => 'boolean',
        'is_paid' => 'boolean',
        'is_public' => 'boolean',
        'member_registration_enabled' => 'boolean',
        'visitor_registration_enabled' => 'boolean',
        'qr_checkin_enabled' => 'boolean',
        'feedback_sent' => 'boolean',
        'gallery_published' => 'boolean',
        'speakers' => 'array',
        'metadata' => 'array',
        'recurrence_ends_at' => 'datetime',
        'ticket_price' => 'decimal:2',
        'revenue_target' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'net_pnl' => 'decimal:2',
        'registration_limit' => 'integer',
        'sponsor_count' => 'integer',
        'recurrence_interval' => 'integer',
        'recurrence_day_of_week' => 'integer',
        'recurrence_week_of_month' => 'integer',
        'recurrence_day_of_month' => 'integer',
        'recurrence_month' => 'integer',
    ];


    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            if (empty($event->id)) {
                $event->id = (string) Str::uuid();
            }
        });
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }
}
