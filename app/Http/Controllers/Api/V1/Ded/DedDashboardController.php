<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;

class DedDashboardController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function show(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return $this->ded->success($this->ded->dashboard($this->ded->admin($request), $request), 'DED dashboard loaded.');
    }
}
