<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackMedia extends Model
{
    use HasFactory;

    protected $table = 'feedback_media';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
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

    public function feedbackForm(): BelongsTo
    {
        return $this->belongsTo(FeedbackForm::class, 'feedback_form_id');
    }
}
