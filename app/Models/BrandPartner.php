<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandPartner extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'brand_partners';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'uuid',
        'name',
        'slug',
        'logo',
        'cover_image',
        'short_description',
        'description',
        'category_id',
        'website',
        'contact_email',
        'contact_number',
        'whatsapp',
        'address',
        'offer_title',
        'offer_description',
        'coupon_code',
        'discount_type',
        'discount_value',
        'valid_from',
        'valid_to',
        'terms_and_conditions',
        'priority',
        'is_featured',
        'is_active',
        'is_verified',
        'is_sponsored',
        'meta_title',
        'meta_description',
        'keywords',
        'created_by',
        'updated_by',
        'metadata',
        'affiliate_link',
        'lead_generation_enabled',
        'qr_code_redemption_enabled',
        'cpc_rate',
        'cpm_rate',
        'cpa_rate',
        'billing_plan',
        'geo_targeting_rules',
        'personalized_campaigns_enabled',
        'campaign_start_date',
        'campaign_end_date',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'is_sponsored' => 'boolean',
        'priority' => 'integer',
        'discount_value' => 'float',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'metadata' => 'array',
        'lead_generation_enabled' => 'boolean',
        'qr_code_redemption_enabled' => 'boolean',
        'personalized_campaigns_enabled' => 'boolean',
        'cpc_rate' => 'float',
        'cpm_rate' => 'float',
        'cpa_rate' => 'float',
        'geo_targeting_rules' => 'array',
        'campaign_start_date' => 'datetime',
        'campaign_end_date' => 'datetime',
    ];

    protected $appends = [
        'logo_url',
        'cover_image_url',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Logo URL Accessor
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        if (Str::startsWith($this->logo, ['http://', 'https://'])) {
            return $this->logo;
        }

        return Storage::disk('public')->url($this->logo);
    }

    // Cover Image URL Accessor
    public function getCoverImageUrlAttribute(): ?string
    {
        if (!$this->cover_image) {
            return null;
        }

        if (Str::startsWith($this->cover_image, ['http://', 'https://'])) {
            return $this->cover_image;
        }

        return Storage::disk('public')->url($this->cover_image);
    }

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(BrandPartnerCategory::class, 'category_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(BrandPartnerView::class, 'brand_partner_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(BrandPartnerClick::class, 'brand_partner_id');
    }

    public function saves(): HasMany
    {
        return $this->hasMany(BrandPartnerSaved::class, 'brand_partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}
