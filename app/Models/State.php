<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    use HasUuids;

    protected $table = 'states';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'status',
    ];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'state_id');
    }
}
