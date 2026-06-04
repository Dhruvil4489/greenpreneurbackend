<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;

class DedCoinsController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'circle_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $paginator = $this->ded->peersIndex($admin, $request);
        $items = collect($paginator->items())->map(fn (User $user) => [
            'user' => $this->ded->userSummary($user),
            'coins_balance' => (int) ($user->coins_balance ?? 0),
        ])->values();

        return $this->ded->success($items, 'DED coin summary loaded.', $this->ded->paginationMeta($paginator));
    }

    public function history(Request $request)
    {
        $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $paginator = $this->ded->coinLedgerQuery($this->ded->admin($request), $request)
            ->latest('created_at')
            ->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED coin history loaded.', $this->ded->paginationMeta($paginator));
    }
}
