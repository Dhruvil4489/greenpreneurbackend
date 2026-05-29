<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorRegistration extends Model
{
    protected $table = 'visitor_registrations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'event_type',
        'event_name',
        'event_date',
        'visitor_full_name',
        'visitor_mobile',
        'visitor_email',
        'visitor_city',
        'visitor_business',
        'visitor_designation',
        'visitor_business_category_id',
        'visitor_business_category',
        'visitor_business_website',
        'visitor_business_brief',
        'invited_by_type',
        'invited_by_user_id',
        'how_known',
        'note',
        'status',
        'reviewed_by_admin_user_id',
        'reviewed_at',
        'coins_awarded',
        'coins_awarded_at',
    ];

    protected $casts = [
        'visitor_business_category_id' => 'integer',
        'event_date' => 'datetime',
        'reviewed_at' => 'datetime',
        'coins_awarded' => 'boolean',
        'coins_awarded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_user_id');
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
