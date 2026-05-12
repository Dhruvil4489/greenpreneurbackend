<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CircleMember extends Model
{
    protected $table = 'circle_members';

    use HasFactory;
    use SoftDeletes;

    public const LEADERSHIP_ROLE_OPTIONS = [
        'founder',
        'director',
        'chair',
        'vice_chair',
        'secretary',
        'committee_leader',
    ];

    public const ROLE_OPTIONS = [
        'member',
        'founder',
        'director',
        'chair',
        'vice_chair',
        'secretary',
        'committee_leader',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'circle_id',
        'user_id',
        'level_1_category_id',
        'level_2_category_id',
        'level_3_category_id',
        'level_4_category_id',
        'role',
        'role_id',
        'status',
        'substitute_count',
        'joined_at',
        'left_at',
        'joined_via',
        'payment_id',
        'paid_at',
        'joined_via_payment',
        'billing_term',
        'paid_starts_at',
        'paid_ends_at',
        'expires_at',
        'zoho_subscription_id',
        'zoho_addon_code',
        'addon_name',
        'circle_subscription_id',
        'subscription_status',
        'payment_status',
        'meta',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'deleted_at' => 'datetime',
        'paid_at' => 'datetime',
        'paid_starts_at' => 'datetime',
        'paid_ends_at' => 'datetime',
        'expires_at' => 'datetime',
        'joined_via_payment' => 'boolean',
        'level_1_category_id' => 'integer',
        'level_2_category_id' => 'integer',
        'level_3_category_id' => 'integer',
        'level_4_category_id' => 'integer',
        'meta' => 'array',
    ];

    public static function roleOptions(): array
    {
        return self::ROLE_OPTIONS;
    }

    protected static function booted(): void
    {
        static::creating(function (CircleMember $member): void {
            if (empty($member->id)) {
                $member->id = (string) Str::uuid();
            }
        });

        static::saving(function (CircleMember $member): void {
            if (! $member->role) {
                return;
            }

            if ($member->role_id && ! $member->isDirty('role')) {
                return;
            }

            try {
                $member->role_id = Role::mustIdByKey($member->role);
            } catch (RuntimeException $exception) {
                Log::error('Circle member role key missing in roles table.', [
                    'circle_member_id' => $member->id,
                    'circle_id' => $member->circle_id,
                    'user_id' => $member->user_id,
                    'role' => $member->role,
                ]);

                throw $exception;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'circle_id');
    }

    public function roleRef(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function categorySelection(): HasOne
    {
        return $this->hasOne(CircleMemberCategorySelection::class, 'circle_member_id');
    }

    public function level1Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategory::class, 'level_1_category_id');
    }

    public function level2Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel2::class, 'level_2_category_id');
    }

    public function level3Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel3::class, 'level_3_category_id');
    }

    public function level4Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel4::class, 'level_4_category_id');
    }
}
