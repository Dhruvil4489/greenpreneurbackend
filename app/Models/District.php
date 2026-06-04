<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasUuids;

    protected $table = 'districts';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'state_id',
        'name',
        'status',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function dedAssignments(): HasMany
    {
        return $this->hasMany(AdminDedDistrict::class, 'district_id');
    }
}
