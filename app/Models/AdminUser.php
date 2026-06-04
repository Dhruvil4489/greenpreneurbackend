<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected $table = 'admin_users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'email',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_user_roles', 'user_id', 'role_id');
    }
}
