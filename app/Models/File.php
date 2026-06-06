<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'files';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uploader_user_id',
        's3_key',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'duration',
        'is_orphaned',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'is_orphaned' => 'boolean',
    ];

    /**
     * Extract file UUIDs from various media formats.
     */
    public static function extractIdsFromMedia($media): array
    {
        if (!$media) {
            return [];
        }

        $decoded = is_string($media) ? json_decode($media, true) : $media;
        if (!is_array($decoded)) {
            $decoded = [$media];
        }

        $ids = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $id = $item['id'] ?? $item['file_id'] ?? $item['fileId'] ?? null;
                if ($id && \Illuminate\Support\Str::isUuid($id)) {
                    $ids[] = $id;
                }
            } elseif (is_string($item) && \Illuminate\Support\Str::isUuid($item)) {
                $ids[] = $item;
            }
        }

        return array_unique($ids);
    }

    /**
     * Retrieve the list of valid, non-orphaned file UUIDs from a list of IDs.
     */
    public static function getValidMediaIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $files = self::whereIn('id', $ids)->get();
        $validIds = [];
        $disk = config('filesystems.default', 'public');

        foreach ($files as $file) {
            if ($file->is_orphaned) {
                continue;
            }

            if (!$file->s3_key || !\Illuminate\Support\Facades\Storage::disk($disk)->exists($file->s3_key)) {
                $file->is_orphaned = true;
                $file->save();
                continue;
            }

            $validIds[] = $file->id;
        }

        return $validIds;
    }
}
