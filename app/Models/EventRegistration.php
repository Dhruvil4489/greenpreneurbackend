<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventRegistration extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'user_id',
        'qr_token',
        'qr_code_path',
        'qr_code_url',
        'qr_code_svg',
        'status',
        'checkin_status',
        'registered_at',
        'checked_in_at',
        'checked_in_by_user_id',
        'source',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'visitor_company',
        'visitor_city',
        'visitor_designation',
        'visitor_business_category_id',
        'visitor_business_category',
        'visitor_business_category_main_id',
        'visitor_business_category_sub_id',
        'visitor_business_category_main',
        'visitor_business_category_sub',
        'visitor_business_website',
        'visitor_business_brief',
        'invited_by_type',
        'invited_by_user_id',
        'how_known',
        'zoho_form_entry_id',
        'zoho_payment_id',
        'zoho_payment_status',
        'zoho_payment_webhook_payload',
        'qr_generated_at',
        'last_qr_scan_at',
        'scan_device_info',
        'attendance_source',
        'metadata',
        'registration_type',
        'registration_request_id',
        'payment_completed_at',
        'zoho_invoice_number',
        'zoho_invoice_status',
        'zoho_invoice_id',
        'zoho_checkout_url',
        'zoho_hosted_page_id',
        'zoho_customer_id',
        'currency',
        'amount',
        'payment_status',
        'payment_required',
        'payment_gateway',
        'payment_url',
        'checkout_url',
        'payment_amount',
        'payment_currency',
        'zoho_payment_link_url',
        'zoho_hosted_page_url',
        'zoho_payment_link_id',
        'zoho_payment_session_id',
        'zoho_invoice_sync_error',
        'zoho_invoice_synced_at',
        'zoho_invoice_pdf_url',
        'zoho_invoice_url',
        'razorpay_paid_at',
        'razorpay_payment_status',
        'razorpay_signature',
        'razorpay_payment_id',
        'razorpay_order_id',
        'visitor_registration_form_url',
    ];

    protected $casts = [
        'visitor_business_category_id' => 'integer',
        'visitor_business_category_main_id' => 'integer',
        'visitor_business_category_sub_id' => 'integer',
        'registered_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'last_qr_scan_at' => 'datetime',
        'qr_generated_at' => 'datetime',
        'metadata' => 'array',
        'zoho_payment_webhook_payload' => 'array',
        'payment_completed_at' => 'datetime',
        'amount' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'payment_required' => 'boolean',
        'zoho_invoice_synced_at' => 'datetime',
        'razorpay_paid_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function businessCategoryMain(): BelongsTo
    {
        return $this->belongsTo(CircleCategory::class, 'visitor_business_category_main_id');
    }

    public function businessCategorySub(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel4::class, 'visitor_business_category_sub_id');
    }

    public function businessCategoryMainPayload(): ?array
    {
        return $this->categoryPayload($this->businessCategoryMain);
    }

    public function businessCategorySubPayload(): ?array
    {
        $category = $this->businessCategorySub;

        if (! $category && empty($this->visitor_business_category_sub_id) && ! empty($this->visitor_business_category_id)) {
            $category = CircleCategoryLevel4::query()->find($this->visitor_business_category_id);
        }

        return $this->categoryPayload($category);
    }

    private function categoryPayload($category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug ?? null,
        ];
    }
}
