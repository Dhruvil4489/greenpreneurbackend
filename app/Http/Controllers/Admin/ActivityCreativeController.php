<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityCreative;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityCreativeController extends Controller
{
    public function index(Request $request): View
    {
        $query = ActivityCreative::query()->with('user:id,first_name,last_name,display_name,email');

        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')) . '%';
            $query->whereHas('user', function ($q) use ($term) {
                $q->where('display_name', 'ILIKE', $term)
                    ->orWhere('first_name', 'ILIKE', $term)
                    ->orWhere('last_name', 'ILIKE', $term)
                    ->orWhere('email', 'ILIKE', $term);
            });
        }

        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->string('activity_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $items = $query->latest('created_at')->paginate(20)->withQueryString();

        return view('admin.activity-creatives.index', [
            'items' => $items,
            'filters' => $request->only(['q', 'activity_type', 'status', 'from_date', 'to_date']),
        ]);
    }
}
