<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class LocationController extends Controller
{
    public function districts(string $state): JsonResponse
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'state_id')) {
            return response()->json(['data' => []]);
        }

        $districts = District::query()
            ->where('state_id', $state)
            ->when(Schema::hasColumn('districts', 'status'), fn ($query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (District $district) => [
                'id' => $district->id,
                'name' => $district->name,
            ])
            ->values();

        return response()->json(['data' => $districts]);
    }
}
