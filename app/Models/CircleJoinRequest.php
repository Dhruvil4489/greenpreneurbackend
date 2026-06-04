<?php

namespace App\Models;

use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CircleJoinRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING_CD_APPROVAL = 'pending_cd_approval';
    public const STATUS_PENDING_ID_APPROVAL = 'pending_id_approval';
    public const STATUS_PENDING_CIRCLE_FEE = 'pending_circle_fee';
    public const STATUS_CIRCLE_MEMBER = 'circle_member';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED_BY_CD = 'rejected_by_cd';
    public const STATUS_REJECTED_BY_ID = 'rejected_by_id';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING_CD_APPROVAL,
        self::STATUS_PENDING_ID_APPROVAL,
        self::STATUS_PENDING_CIRCLE_FEE,
        self::STATUS_CIRCLE_MEMBER,
        self::STATUS_PAID,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'circle_id',
        'reason_for_joining',
        'level1_category_id',
        'level2_category_id',
        'level3_category_id',
        'level4_category_id',
        'status',
        'requested_at',
        'cd_approved_by',
        'cd_approved_at',
        'cd_rejected_by',
        'cd_rejected_at',
        'cd_rejection_reason',
        'id_approved_by',
        'id_approved_at',
        'id_rejected_by',
        'id_rejected_at',
        'id_rejection_reason',
        'ded_approval_status',
        'ded_approved_by',
        'ded_approved_at',
        'fee_marked_at',
        'fee_paid_at',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'cd_approved_at' => 'datetime',
        'cd_rejected_at' => 'datetime',
        'id_approved_at' => 'datetime',
        'id_rejected_at' => 'datetime',
        'ded_approved_at' => 'datetime',
        'fee_marked_at' => 'datetime',
        'fee_paid_at' => 'datetime',
        'notes' => 'array',
        'level1_category_id' => 'integer',
        'level2_category_id' => 'integer',
        'level3_category_id' => 'integer',
        'level4_category_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (! $request->id) {
                $request->id = (string) Str::uuid();
            }
        });

        static::saving(function (self $request): void {
            if (! Schema::hasTable('circle_join_requests') || ! Schema::hasColumn('circle_join_requests', 'ded_approval_status')) {
                return;
            }

            $status = (string) $request->status;
            $dedStatus = (string) ($request->ded_approval_status ?: 'pending');

            if (in_array($status, [self::STATUS_PENDING_CIRCLE_FEE, self::STATUS_CIRCLE_MEMBER, self::STATUS_PAID], true) && $dedStatus === 'pending') {
                $request->ded_approval_status = 'approved';

                if (Schema::hasColumn('circle_join_requests', 'ded_approved_by') && ! $request->ded_approved_by) {
                    $request->ded_approved_by = $request->id_approved_by ?: $request->cd_approved_by;
                }

                if (Schema::hasColumn('circle_join_requests', 'ded_approved_at') && ! $request->ded_approved_at) {
                    $request->ded_approved_at = $request->id_approved_at ?: ($request->cd_approved_at ?: ($request->fee_marked_at ?: now()));
                }
            }

            if (in_array($status, [self::STATUS_REJECTED_BY_CD, self::STATUS_REJECTED_BY_ID, self::STATUS_CANCELLED], true) && $dedStatus === 'pending') {
                $request->ded_approval_status = 'rejected';
            }
        });
    }


    public function effectiveDedApprovalStatus(): string
    {
        $status = (string) $this->status;
        $dedStatus = (string) ($this->ded_approval_status ?: 'pending');

        if (in_array($status, [self::STATUS_PENDING_CIRCLE_FEE, self::STATUS_CIRCLE_MEMBER, self::STATUS_PAID], true) && $dedStatus === 'pending') {
            return 'approved';
        }

        if (in_array($status, [self::STATUS_REJECTED_BY_CD, self::STATUS_REJECTED_BY_ID, self::STATUS_CANCELLED], true) && $dedStatus === 'pending') {
            return 'rejected';
        }

        return $dedStatus;
    }

    public function paymentStatusLabel(): string
    {
        if ($this->status === self::STATUS_PENDING_CIRCLE_FEE) {
            return $this->fee_paid_at ? 'Paid' : 'Unpaid';
        }

        if (in_array((string) $this->status, [self::STATUS_CIRCLE_MEMBER, self::STATUS_PAID], true)) {
            return 'Paid';
        }

        return 'Not Applicable';
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_CD_APPROVAL,
            self::STATUS_PENDING_ID_APPROVAL,
            self::STATUS_PENDING_CIRCLE_FEE,
        ]);
    }

    public function scopeForCircle(Builder $query, string $circleId): Builder
    {
        return $query->where('circle_id', $circleId);
    }

    public function scopeVisibleToAdminUser(Builder $query, ?AdminUser $adminUser): Builder
    {
        if (! $adminUser) {
            return $query->whereRaw('1=0');
        }

        if (AdminAccess::isSuper($adminUser)) {
            return $query;
        }

        if (AdminAccess::isDed($adminUser)) {
            AdminCircleScope::applyToActivityQuery($query, $adminUser, 'circle_join_requests.user_id', null);

            return $query;
        }

        $allowedCircleIds = AdminAccess::allowedCircleIds($adminUser);

        if ($allowedCircleIds === []) {
            return $query->whereRaw('1=0');
        }

        return $query->whereIn('circle_id', $allowedCircleIds);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'circle_id');
    }

    public function cdApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cd_approved_by');
    }

    public function cdRejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cd_rejected_by');
    }

    public function idApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_approved_by');
    }

    public function idRejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_rejected_by');
    }

    public function dedApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ded_approved_by');
    }


    public function level1Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategory::class, 'level1_category_id');
    }

    public function level2Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel2::class, 'level2_category_id');
    }

    public function level3Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel3::class, 'level3_category_id');
    }

    public function level4Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel4::class, 'level4_category_id');
    }
}

