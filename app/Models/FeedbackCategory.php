<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackCategory extends Model
{
    use HasFactory;

    protected $table = 'feedback_categories';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function feedbackForms(): HasMany
    {
        return $this->hasMany(FeedbackForm::class, 'category_id');
    }
}
