<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class CircleCategoryLevel2 extends Model
{
    use HasFactory;

    protected $table = 'circle_category_level2';

    public function getTable()
    {
        return Schema::hasTable('level2_categories')
            ? 'level2_categories'
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
}
