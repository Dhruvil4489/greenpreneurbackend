<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class P2pMeeting extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'p2p_meetings';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'initiator_user_id',
        'peer_user_id',
        'meeting_date',
        'meeting_place',
        'remarks',
        'media',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
        'media' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'peer_user_id');
    }
}
