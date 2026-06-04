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
        'phone',
        'first_name',
        'middle_name',
        'last_name',
        'nickname',
        'email',
        'company',
        'job_title',
        'notes',
        'emails',
        'phones',
        'addresses',
    ];

    protected $casts = [
        'emails' => 'array',
        'phones' => 'array',
        'addresses' => 'array',
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
