<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DedLocationService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function __construct(private readonly DedLocationService $dedLocationService)
    {
    }

    public function districts(string $state): JsonResponse
    {
        return response()->json([
            'data' => $this->dedLocationService->getAvailableDistrictsByState($state)->map(fn (object $district): array => [
                'id' => $district->id,
                'name' => $district->name,
                'district_name' => $district->district_name ?? $district->name,
                'district_id' => $district->district_id ?? null,
            ])->values(),
        ]);
    }
}
