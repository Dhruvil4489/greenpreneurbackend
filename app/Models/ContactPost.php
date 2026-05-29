<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContactPost extends Model
{
    use HasFactory;

    protected $table = 'contact_posts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'full_name',
        'phonetic_name',
        'mobile_number',
        'alternate_mobile_number',
        'email',
        'company',
        'job_title',
        'address',
        'im',
        'contact_date',
        'related_persons',
        'nickname',
        'website',
        'notes',
        'source_accounts',
        'follow_system',
    ];

    protected $casts = [
        'related_persons' => 'array',
        'source_accounts' => 'array',
        'follow_system' => 'boolean',
        'contact_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $contactPost): void {
            if (blank($contactPost->id)) {
                $contactPost->id = (string) Str::uuid();
            }
        });
    }
}
