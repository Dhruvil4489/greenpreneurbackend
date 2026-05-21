<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FeedbackMedia extends Model
{
    use HasFactory;

    protected $table = 'feedback_media';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'feedback_form_id',
        'file_path',
        'file_url',
        'file_type',
        'mime_type',
        'original_name',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function feedbackForm(): BelongsTo
    {
        return $this->belongsTo(FeedbackForm::class, 'feedback_form_id');
    }
}
