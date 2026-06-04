<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;

class DedPeersController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function circles(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $paginator = $this->ded->circlesIndex($this->ded->admin($request), $request);

        return $this->ded->success($paginator->items(), 'DED circles loaded.', $this->ded->paginationMeta($paginator));
    }

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'circle_id' => ['nullable', 'uuid'],
            'membership_status' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $paginator = $this->ded->peersIndex($admin, $request);
        $items = collect($paginator->items())->map(fn (User $user) => $this->ded->userSummary($user))->values();

        return $this->ded->success($items, 'DED peers loaded.', $this->ded->paginationMeta($paginator));
    }

    public function show(Request $request, string $id)
    {
        $admin = $this->ded->admin($request);
        $this->ded->assertUserInScope($admin, $id);
        $user = User::query()->with(['city', 'activeCircle', 'circleMemberships.circle'])->findOrFail($id);

        return $this->ded->success($user, 'DED peer loaded.');
    }
}
