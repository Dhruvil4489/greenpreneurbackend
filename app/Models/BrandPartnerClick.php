<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPartnerClick extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'brand_partner_clicks';

    protected $keyType = 'string';

    public $incrementing = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'user_id',
        'brand_partner_id',
        'click_type',
        'ip',
        'device',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
