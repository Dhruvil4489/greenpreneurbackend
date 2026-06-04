<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ScanAppUser;
use Illuminate\Http\Request;

class ScanAppAuthController extends BaseApiController
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $scanner = ScanAppUser::query()
            ->where('username', $credentials['username'])
            ->first();

        if (! $scanner || ! $scanner->is_active || ! $scanner->checkPassword($credentials['password'])) {
            return $this->error('Invalid credentials or inactive scanner account.', 401);
        }

        $scanner->forceFill(['last_login_at' => now()])->save();

        return $this->success([
            'token' => $scanner->createToken('scan-app-token')->plainTextToken,
            'scanner' => $this->scannerPayload($scanner),
        ], 'Login successful.');
    }

    public function me(Request $request)
    {
        $scanner = $this->scanner($request);
        if (! $scanner) {
            return $this->error('Scanner authentication required.', 401);
        }

        if (! $scanner->is_active) {
            return $this->error('Scanner account is inactive.', 403);
        }

        return $this->success(['scanner' => $this->scannerPayload($scanner)], 'Scanner profile fetched successfully.');
    }

    public function logout(Request $request)
    {
        $scanner = $this->scanner($request);
        if (! $scanner) {
            return $this->error('Scanner authentication required.', 401);
        }

        $request->user()?->currentAccessToken()?->delete();

        return $this->success([], 'Logout successful.');
    }

    private function scanner(Request $request): ?ScanAppUser
    {
        $user = $request->user();

        return $user instanceof ScanAppUser ? $user : null;
    }

    private function scannerPayload(ScanAppUser $scanner): array
    {
        return [
            'id' => $scanner->id,
            'name' => $scanner->name,
            'username' => $scanner->username,
            'hotel_name' => $scanner->hotel_name,
            'event_id' => $scanner->event_id,
            'event_name' => $scanner->event_name,
        ];
    }
}
