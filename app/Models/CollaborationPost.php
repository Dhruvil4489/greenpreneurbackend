<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CollaborationPost extends Model
{
    use HasFactory;

    protected $table = 'collaboration_posts';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DELETED = 'deleted';

    public const COMPLETION_INCOMPLETE = 'incomplete';
    public const COMPLETION_COMPLETED = 'completed';

    protected $fillable = [
        'id',
        'user_id',
        'collaboration_type_id',
        'collaboration_type',
        'title',
        'description',
        'scope',
        'countries_of_interest',
        'preferred_model',
        'industry_id',
        'business_stage',
        'years_in_operation',
        'urgency',
        'status',
        'completion_status',
        'completed_at',
        'posted_at',
        'expires_at',
    ];

    protected $casts = [
        'countries_of_interest' => 'array',
        'posted_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $post): void {
            if (blank($post->id)) {
                $post->id = (string) Str::uuid();
            }

            if (blank($post->completion_status)) {
                $post->completion_status = self::COMPLETION_INCOMPLETE;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    public function collaborationType(): BelongsTo
    {
        return $this->belongsTo(CollaborationType::class, 'collaboration_type_id');
    }

    public function interests(): HasMany
    {
        return $this->hasMany(CollaborationPostInterest::class, 'post_id');
    }

    public function meetingRequests(): HasMany
    {
        return $this->hasMany(CollaborationPostMeetingRequest::class, 'post_id');
    }
}
