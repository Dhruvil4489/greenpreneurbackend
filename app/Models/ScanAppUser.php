<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class ScanAppUser extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;
    use Notifiable;

    protected $table = 'scan_app_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'username',
        'password_hash',
        'hotel_name',
        'event_id',
        'event_name',
        'is_active',
        'created_by_admin_id',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function checkPassword(string $password): bool
    {
        return Hash::check($password, (string) $this->password_hash);
    }
}
