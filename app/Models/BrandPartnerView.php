<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPartnerView extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'brand_partner_views';

    protected $keyType = 'string';

    public $incrementing = false;

    // Map viewed_at to the created_at event
    const CREATED_AT = 'viewed_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'user_id',
        'brand_partner_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function brandPartner(): BelongsTo
    {
        return $this->belongsTo(BrandPartner::class, 'brand_partner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
