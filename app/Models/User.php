<?php

namespace App\Models;

use App\Support\CoinMilestoneResolver;
use App\Support\ContributionMilestoneResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Throwable;

class User extends Authenticatable
{
    public const STATUS_FREE_TRIAL = 'free_trial_peer';
    public const STATUS_FREE = 'free_peer';

    private const FREE_PEER_STATUS_CANDIDATES = [self::STATUS_FREE, 'Free Peer', 'Free_peer'];

    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'first_name',
        'last_name',
        'display_name',
        'email',
        'phone',
        'password',
        'password_hash',
        'company_name',
        'designation',
        'gender',
        'dob',
        'experience_years',
        'experience_summary',
        'city_id',
        'city',
        'skills',
        'interests',
        'social_links',
        'profile_photo_id',
        'cover_photo_id',
        'membership_status',
        'membership_expiry',
        'coins_balance',
        'coins_remark',
        'coin_medal_rank',
        'coin_milestone_title',
        'coin_milestone_meaning',
        'contribution_award_name',
        'contribution_award_recognition',
        'profile_photo_url',
        'short_bio',
        'long_bio_html',
        'industry_tags',
        'business_type',
        'turnover_range',
        'introduced_by',
        'members_introduced_count',
        'life_impacted_count',
        'influencer_stars',
        'target_regions',
        'target_business_categories',
        'main_business_category_id',
        'business_category_id',
        'city_of_residence',
        'referred_by_user_id',
        'hobbies_interests',
        'leadership_roles',
        'is_sponsored_member',
        'public_profile_slug',
        'special_recognitions',
        'gdpr_deleted_at',
        'anonymized_at',
        'is_gdpr_exported',
        'last_login_at',
        'last_seen_at',
        'is_online',
        'profile_photo_file_id',
        'cover_photo_file_id',
        'zoho_customer_id',
        'zoho_subscription_id',
        'zoho_plan_code',
        'zoho_last_invoice_id',
        'membership_starts_at',
        'membership_ends_at',
        'last_payment_at',
        'active_circle_subscription_id',
        'circle_joined_at',
        'circle_expires_at',
        'active_circle_id',
        'active_circle_addon_code',
        'active_circle_addon_name',
        'welcome_membership_email_sent_at',
        'welcome_membership_email_status',
        'welcome_membership_email_error',
        'welcome_membership_email_plan_code',
    ];

    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'industry_tags' => 'array',
        'target_regions' => 'array',
        'target_business_categories' => 'array',
        'hobbies_interests' => 'array',
        'leadership_roles' => 'array',
        'special_recognitions' => 'array',
        'membership_expiry' => 'datetime',
        'gdpr_deleted_at' => 'datetime',
        'anonymized_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'membership_starts_at' => 'datetime',
        'membership_ends_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'circle_joined_at' => 'datetime',
        'circle_expires_at' => 'datetime',
        'welcome_membership_email_sent_at' => 'datetime',
        'dob' => 'date',
        'skills' => 'array',
        'interests' => 'array',
        'social_links' => 'array',
        'coins_balance' => 'integer',
        'is_sponsored_member' => 'boolean',
        'life_impacted_count' => 'integer',
        'is_online' => 'boolean',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getLifeImpactedCountAttribute($value): int
    {
        return (int) ($value ?? 0);
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $user->syncMembershipExpiryAttributes();
            $user->syncCoinMilestoneAttributes();
            $user->syncContributionMilestoneAttributes();
        });

        static::creating(function (self $user): void {
            if (empty($user->id)) {
                $user->id = Str::uuid()->toString();
            }

            if (empty($user->display_name)) {
                $user->display_name = trim($user->first_name . ' ' . ($user->last_name ?? ''));
            }
        });
    }

    public function syncCoinMilestoneAttributes(): bool
    {
        $resolved = CoinMilestoneResolver::resolve($this->coins_balance);

        $changes = [
            'coin_medal_rank' => $resolved['medal_rank'],
            'coin_milestone_title' => $resolved['title'],
            'coin_milestone_meaning' => $resolved['meaning'],
        ];

        $dirty = false;

        foreach ($changes as $attribute => $value) {
            if ($this->{$attribute} !== $value) {
                $this->{$attribute} = $value;
                $dirty = true;
            }
        }

        return $dirty;
    }

    public function syncContributionMilestoneAttributes(): bool
    {
        $resolved = ContributionMilestoneResolver::resolve($this->members_introduced_count);

        $changes = [
            'contribution_award_name' => $resolved['award_name'],
            'contribution_award_recognition' => $resolved['recognition'],
        ];

        $dirty = false;

        foreach ($changes as $attribute => $value) {
            if ($this->{$attribute} !== $value) {
                $this->{$attribute} = $value;
                $dirty = true;
            }
        }

        return $dirty;
    }

    public function syncMembershipExpiryAttributes(): bool
    {
        $targetExpiry = null;

        if ($this->isDirty('membership_ends_at')) {
            $targetExpiry = $this->membership_ends_at;
        } elseif ($this->isDirty('membership_expiry')) {
            $targetExpiry = $this->membership_expiry;
        } elseif (! $this->membershipDatesMatch()) {
            $targetExpiry = $this->membership_ends_at ?? $this->membership_expiry;
        } else {
            return false;
        }

        $this->membership_ends_at = $targetExpiry;
        $this->membership_expiry = $targetExpiry;

        return true;
    }

    public function membershipDatesMatch(): bool
    {
        return $this->normalizedMembershipDate($this->membership_ends_at) === $this->normalizedMembershipDate($this->membership_expiry);
    }

    private function normalizedMembershipDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        return (string) $value;
    }

    public function links(): HasMany
    {
        return $this->hasMany(UserLink::class);
    }

    public function userLinks(): HasMany
    {
        return $this->hasMany(UserLink::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function cityRelation(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function introducedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'introduced_by');
    }

    public function referredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function mainBusinessCategory(): BelongsTo
    {
        return $this->belongsTo(CircleCategory::class, 'main_business_category_id');
    }

    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(Level4Category::class, 'business_category_id');
    }

    public function foundedCircles(): HasMany
    {
        return $this->hasMany(Circle::class, 'founder_user_id');
    }

    public function circleMembers(): HasMany
    {
        return $this->hasMany(CircleMember::class);
    }

    public function circleMemberships(): HasMany
    {
        return $this->hasMany(CircleMember::class, 'user_id');
    }

    public function circleSubscriptions(): HasMany
    {
        return $this->hasMany(CircleSubscription::class, 'user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'user_id');
    }

    public function activeCircle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'active_circle_id');
    }

    public function activeCircleSubscription(): BelongsTo
    {
        return $this->belongsTo(CircleSubscription::class, 'active_circle_subscription_id');
    }

    public function circles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class, 'circle_members', 'user_id', 'circle_id')
            ->withPivot(['status', 'joined_at', 'deleted_at'])
            ->wherePivot('status', 'approved')
            ->wherePivotNull('deleted_at')
            ->orderByPivot('joined_at', 'desc');
    }

    public function adminDisplayName(): string
    {
        $displayName = trim((string) ($this->display_name ?? ''));

        if ($displayName !== '') {
            return $displayName;
        }

        $fullName = trim(
            trim((string) ($this->first_name ?? '')) . ' ' . trim((string) ($this->last_name ?? ''))
        );

        return $fullName !== '' ? $fullName : 'Unknown';
    }

    public function getDisplayNameAttribute()
    {
        $firstName = trim($this->first_name ?? '');
        $lastName = trim($this->last_name ?? '');

        $fullName = trim($firstName . ' ' . $lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        return $this->email;
    }

    public function adminCompanyLabel(): string
    {
        $company = trim((string) ($this->company_name ?? ''));

        if ($company !== '') {
            return $company;
        }

        $businessName = trim((string) ($this->business_name ?? ''));

        return $businessName !== '' ? $businessName : 'No Company';
    }

    public function adminCityLabel(): string
    {
        $city = trim((string) ($this->city ?? ''));

        return $city !== '' ? $city : 'No City';
    }

    public function adminCircleLabel(): string
    {
        if ($this->relationLoaded('circleMembers')) {
            $name = trim((string) optional($this->circleMembers->first()?->circle)->name);
            return $name !== '' ? $name : 'No Circle';
        }

        if ($this->relationLoaded('circles')) {
            $name = trim((string) optional($this->circles->first())->name);
            return $name !== '' ? $name : 'No Circle';
        }

        try {
            $member = $this->circleMembers()
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->with(['circle:id,name'])
                ->orderByDesc('joined_at')
                ->first();

            $name = trim((string) optional($member?->circle)->name);

            return $name !== '' ? $name : 'No Circle';
        } catch (\Throwable $e) {
            return 'No Circle';
        }
    }

    public function adminFounderOptionLabel(): string
    {
        return implode(PHP_EOL, [
            $this->adminDisplayName(),
            $this->adminCompanyLabel(),
            $this->adminCityLabel(),
            $this->adminCircleLabel(),
        ]);
    }

    public function adminName(): string
    {
        $displayName = trim((string) ($this->display_name ?? ''));

        if ($displayName !== '') {
            return $displayName;
        }

        $fullName = trim(
            trim((string) ($this->first_name ?? '')) . ' ' . trim((string) ($this->last_name ?? ''))
        );

        if ($fullName !== '') {
            return $fullName;
        }

        return $this->adminDisplayName();
    }

    public function adminCompany(): string
    {
        return $this->adminCompanyLabel();
    }

    public function adminCity(): string
    {
        return $this->adminCityLabel();
    }

    public function adminCircleName(): string
    {
        return $this->adminCircleLabel();
    }

    public function adminDisplayParts(): array
    {
        return [
            $this->adminName(),
            $this->adminCompany(),
            $this->adminCity(),
            $this->adminCircleName(),
        ];
    }

    public function adminDisplayLabel(): string
    {
        return implode(PHP_EOL, $this->adminDisplayParts());
    }

    public function adminDisplayInlineLabel(): string
    {
        return implode(' — ', $this->adminDisplayParts());
    }

    public function adminNameCompanyCityLabel(): string
    {
        return implode(', ', [
            $this->adminName(),
            $this->adminCompanyLabel(),
            $this->adminCityLabel(),
        ]);
    }

    public function adminFounderDropdownLabel(): string
    {
        return $this->adminNameCompanyCityLabel();
    }

    public function isMembershipExpired(): bool
    {
        $membershipEndsAt = $this->membership_ends_at ?? $this->membership_expiry;

        return $membershipEndsAt !== null && $membershipEndsAt->isPast();
    }

    public function hasExpiredFreeTrial(): bool
    {
        return (string) $this->membership_status === self::STATUS_FREE_TRIAL
            && $this->membership_ends_at !== null
            && $this->membership_ends_at->lessThanOrEqualTo(now());
    }

    public function expireFreeTrialIfNeeded(): bool
    {
        if (! $this->hasExpiredFreeTrial()) {
            return false;
        }

        $this->forceFill([
            'membership_status' => self::STATUS_FREE,
        ])->save();

        return true;
    }

    public function getEffectiveMembershipStatusAttribute(): ?string
    {
        if ($this->hasExpiredFreeTrial()) {
            return self::STATUS_FREE;
        }

        if ($this->isMembershipExpired()) {
            return self::freePeerMembershipStatus();
        }

        return $this->membership_status;
    }

    public static function freePeerMembershipStatus(): string
    {
        $configuredStatuses = (array) config('membership.statuses', []);

        foreach (self::FREE_PEER_STATUS_CANDIDATES as $candidate) {
            if (in_array($candidate, $configuredStatuses, true)) {
                return $candidate;
            }
        }

        return self::STATUS_FREE;
    }

    public function geoLocation(): HasOne
    {
        return $this->hasOne(UserGeoLocation::class, 'user_id');
    }

    public function requestedConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'requester_id');
    }

    public function receivedConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'addressee_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function savedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_saves', 'user_id', 'post_id')->withTimestamps();
    }

    public function postComments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'created_by_user_id');
    }

    public function eventRsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function relatedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'related_user_id');
    }

    public function verifiedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'verified_by_admin_id');
    }

    public function coinLedgers(): HasMany
    {
        return $this->hasMany(CoinLedger::class);
    }

    public function coinsLedger(): HasMany
    {
        return $this->hasMany(CoinsLedger::class, 'user_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class);
    }

    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class);
    }

    public function chatsInitiated(): HasMany
    {
        return $this->hasMany(Chat::class, 'user1_id');
    }

    public function chatsReceived(): HasMany
    {
        return $this->hasMany(Chat::class, 'user2_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function circleChatMessagesSent(): HasMany
    {
        return $this->hasMany(CircleChatMessage::class, 'sender_id');
    }

    public function circleChatMessageReads(): HasMany
    {
        return $this->hasMany(CircleChatMessageRead::class, 'user_id');
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(UserPushToken::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_user_roles', 'user_id', 'role_id');
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(FileModel::class, 'uploader_user_id');
    }

    public function adminAuditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'admin_user_id');
    }

    public function referralLinks(): HasMany
    {
        return $this->hasMany(ReferralLink::class, 'referrer_user_id');
    }

    public function referralDataAsReferrer(): HasMany
    {
        return $this->hasMany(ReferralData::class, 'referrer_user_id');
    }

    public function referralDataAsReferred(): HasMany
    {
        return $this->hasMany(ReferralData::class, 'referred_user_id');
    }

    public function visitorLeads(): HasMany
    {
        return $this->hasMany(VisitorLead::class, 'converted_user_id');
    }

    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function profilePhotoFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'profile_photo_file_id');
    }

    public function coverPhotoFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'cover_photo_file_id');
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->profile_photo_file_id) {
            return null;
        }

        return url('/api/v1/files/' . $this->profile_photo_file_id);
    }

    public function isFreeMember(): bool
    {
        return in_array((string) $this->effective_membership_status, [self::STATUS_FREE, self::STATUS_FREE_TRIAL], true);
    }

    public function isPaidMember(): bool
    {
        return ! $this->isFreeMember();
    }

    public function publicProfileArray(): array
    {
        $name = (string) ($this->getAttribute('name')
            ?? $this->display_name
            ?? trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')));

        $companyName = (string) ($this->getAttribute('company_name') ?? '');
        $city = (string) ($this->getAttribute('city') ?? '');
        $industry = (string) ($this->getAttribute('industry') ?? '');

        if ((blank($companyName) || blank($city) || blank($industry)) && method_exists($this, 'profile')) {
            try {
                $profile = $this->relationLoaded('profile')
                    ? $this->getRelation('profile')
                    : $this->profile()->first();

                $companyName = blank($companyName) ? (string) ($profile->company_name ?? '') : $companyName;
                $city = blank($city) ? (string) ($profile->city ?? '') : $city;
                $industry = blank($industry) ? (string) ($profile->industry ?? '') : $industry;
            } catch (Throwable $e) {
                // Relation is optional in this project scope.
            }
        }

        return [
            'id' => (string) $this->id,
            'name' => $name,
            'company_name' => $companyName,
            'email' => (string) ($this->email ?? ''),
            'city' => $city,
            'industry' => $industry,
        ];
    }
}
