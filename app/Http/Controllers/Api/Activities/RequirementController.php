<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Activities\StoreRequirementRequest;
use App\Events\ActivityCreated;
use App\Models\Post;
use App\Models\Requirement;
use App\Services\Coins\CoinsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequirementController extends BaseApiController
{
    protected function addUrlsToMedia(?array $media): array
    {
        if (empty($media)) {
            return [];
        }

        return collect($media)->map(function ($item) {
            $id   = $item['id']   ?? null;
            $type = $item['type'] ?? 'image';

            return [
                'id'   => $id,
                'type' => $type,
                'url'  => $id ? url('/api/v1/files/' . $id) : null,
            ];
        })->all();
    }

    /**
     * Create a feed post for a newly created requirement.
     *
     * This must NOT throw; on failure we just log the error.
     */
    protected function createPostForRequirement(Requirement $requirement): void
    {
        try {
            $alreadyExists = Post::where('source_type', 'requirement')
                ->where('source_id', $requirement->id)
                ->exists();

            if ($alreadyExists) {
                return;
            }

            $mediaForPost = $this->addUrlsToMedia($requirement->media ?? []);

            Post::create([
                'user_id'           => $requirement->user_id,
                'circle_id'         => null,
                'content_text'      => trim(($requirement->subject ?? '') . ' - ' . ($requirement->description ?? '')),
                'media'             => $mediaForPost,
                'tags'              => $requirement->tags ?? [],
                'visibility'        => $requirement->visibility ?? 'public',
                'moderation_status' => 'pending',
                'sponsored'         => false,
                'is_deleted'        => false,
                'source_type'       => 'requirement',
                'source_id'         => $requirement->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create post for requirement', [
                'requirement_id' => $requirement->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    public function store(StoreRequirementRequest $request)
    {
        // Postman example (requirement create - MUST NOT notify):
        // {
        //   "subject": "Need growth support",
        //   "description": "Looking for partners to scale",
        //   "visibility": "public"
        // }
        // Verify SQL (should return no new requirement notification rows):
        // select * from notifications where user_id = '<any-target-user-uuid>' order by created_at desc limit 20;

        $user = $request->user();
        $data = $request->validated();

        $media = null;
        if (! empty($data['media_id'])) {
            $media = [[
                'id' => $data['media_id'],
                'type' => 'image',
            ]];
        }

        try {
            $regionFilter = [
                'region_label' => $data['region_label'],
                'city_name' => $data['city_name'],
            ];

            $categoryFilter = [
                'category' => $data['category'],
            ];

            $requirement = Requirement::create([
                'user_id' => $user->id,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'media' => $media,
                'region_filter' => $regionFilter,
                'category_filter' => $categoryFilter,
                'status' => $data['status'] ?? 'open',
            ]);

            $coinsLedger = app(CoinsService::class)->rewardForActivity(
                $user,
                'requirement',
                null,
                'Activity: requirement',
                $user->id
            );

            if ($coinsLedger) {
                $requirement->setAttribute('coins', [
                    'earned' => $coinsLedger->amount,
                    'balance_after' => $coinsLedger->balance_after,
                ]);
            }

            // NEW: auto-create post (do NOT award coins again)
            $this->createPostForRequirement($requirement);

            event(new ActivityCreated(
                'Requirement',
                $requirement,
                (string) $user->id,
                null
            ));

            // Build response payload from the model
            $data = $requirement->toArray();

            $postId = Post::query()
                ->where('source_id', $requirement->id)
                ->whereIn('source_type', ['requirement', 'requirements'])
                ->where(function ($query) {
                    $query->where('is_deleted', false)
                        ->orWhereNull('is_deleted');
                })
                ->latest('created_at')
                ->value('id');

            $data['post_id'] = $postId;

            // Ensure media includes URL
            $data['media'] = $this->addUrlsToMedia($requirement->media ?? []);

            // If you attach coins as a dynamic attribute like $requirement->coins,
            // keep that as is:
            if ($requirement->getAttribute('coins')) {
                $data['coins'] = $requirement->getAttribute('coins');
            }

            return $this->success($data, 'Requirement created successfully', 201);
        } catch (Throwable $e) {
            Log::error('Create requirement failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('app.env') !== 'production') {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }

            return $this->error('Failed to create requirement', 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Requirement::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function (Requirement $requirement) {
            $row = $requirement->toArray();
            $row['media'] = $this->addUrlsToMedia($requirement->media ?? []);

            return $row;
        });

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $requirement = Requirement::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $requirement) {
            return $this->error('Requirement not found', 404);
        }

        $data = $requirement->toArray();
        $data['media'] = $this->addUrlsToMedia($requirement->media ?? []);

        return $this->success($data);
    }
}
