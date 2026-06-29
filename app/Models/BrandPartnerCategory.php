<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandPartnerCategory extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'brand_partner_categories';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'icon',
        'color',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function brandPartners(): HasMany
    {
        return $this->hasMany(BrandPartner::class, 'category_id');
    }
}
