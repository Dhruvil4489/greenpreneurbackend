<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminDedDistrict extends Model
{
    use HasUuids;

    protected $table = 'admin_ded_districts';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'admin_user_id',
        'user_id',
        'state_id',
        'district_id',
        'state_name',
        'district_name',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
