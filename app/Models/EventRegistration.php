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
        'zoho_form_entry_id',
        'zoho_payment_id',
        'zoho_payment_status',
        'qr_generated_at',
        'last_qr_scan_at',
        'scan_device_info',
        'attendance_source',
        'metadata',
        'registration_type',
        'payment_completed_at',
        'zoho_invoice_number',
        'zoho_invoice_id',
        'zoho_checkout_url',
        'zoho_hosted_page_id',
        'zoho_customer_id',
        'currency',
        'amount',
        'payment_status',
        'payment_required',
        'zoho_invoice_sync_error',
        'zoho_invoice_synced_at',
        'zoho_invoice_pdf_url',
        'zoho_invoice_url',
        'razorpay_paid_at',
        'razorpay_payment_status',
        'razorpay_signature',
        'razorpay_payment_id',
        'razorpay_order_id',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'last_qr_scan_at' => 'datetime',
        'qr_generated_at' => 'datetime',
        'metadata' => 'array',
        'payment_completed_at' => 'datetime',
        'amount' => 'decimal:2',
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
}
