<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditService
{
    public function log(User|\App\Models\AdminUser $actor, string $action, string $resourceType, ?string $resourceId, array $old = [], array $new = [], ?Request $request = null): void
    {
        AdminAuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $actor->id,
            'action' => $action,
            'target_table' => $resourceType,
            'target_id' => $resourceId,
            'details' => [
                'old_values' => $old,
                'new_values' => $new,
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => (string) $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
