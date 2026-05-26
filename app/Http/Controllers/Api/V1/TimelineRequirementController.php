<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Requirement\RequirementTimelineResource;
use App\Models\Requirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimelineRequirementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Requirement::query()
            ->select('requirements.*')
            ->selectRaw('rp.post_id as post_id')
            ->with('user')
            ->leftJoinSub(
                DB::table('posts')
                    ->selectRaw('DISTINCT ON (source_id) source_id, id as post_id')
                    ->where('source_type', '=', 'requirement')
                    ->where('is_deleted', '=', false)
                    ->orderBy('source_id')
                    ->orderByDesc('created_at'),
                'rp',
                'rp.source_id',
                '=',
                'requirements.id'
            )
            ->where('requirements.status', '=', 'open')
            ->whereNull('requirements.deleted_at')
            ->orderByDesc('requirements.created_at');

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Open requirements fetched successfully.',
            'data' => RequirementTimelineResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }
}
