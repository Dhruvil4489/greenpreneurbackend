<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackForm extends Model
{
    use HasFactory;

    protected $table = 'feedback_forms';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'category_id',
        'category',
        'subject',
        'question',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeedbackCategory::class, 'category_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(FeedbackMedia::class, 'feedback_form_id');
    }
}
