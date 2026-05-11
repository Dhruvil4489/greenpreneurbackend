<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class CircleCategoryLevel4 extends Model
{
    use HasFactory;

    protected $table = 'circle_category_level4';

    public function getTable()
    {
        return Schema::hasTable('level4_categories')
            ? 'level4_categories'
            : $this->table;
    }

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function circleCategory(): BelongsTo
    {
        return $this->belongsTo(CircleCategory::class, 'circle_category_id');
    }

    public function level2Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel2::class, 'level2_id');
    }

    public function level3Category(): BelongsTo
    {
        return $this->belongsTo(CircleCategoryLevel3::class, 'level3_id');
    }
}
