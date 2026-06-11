<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ads\StoreAdRequest;
use App\Http\Requests\Admin\Ads\UpdateAdRequest;
use App\Models\Ad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $ads = Ad::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where('title', 'ILIKE', '%' . $search . '%');
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.ads.index', compact('ads', 'search'));
    }

    public function create(): View
    {
        return view('admin.ads.create', [
            'ad' => new Ad(['is_active' => true]),
        ]);
    }

    public function store(StoreAdRequest $request): RedirectResponse
    {
        $data = $this->payload($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request);
        }

        $adminUser = Auth::guard('admin')->user();
        $data['created_by'] = $adminUser?->id;

        Ad::query()->create($data);

        return redirect()->route('admin.ads.index')->with('success', 'Ad created successfully.');
    }

    public function edit(Ad $ad): View
    {
        return view('admin.ads.edit', [
            'ad' => $ad,
        ]);
    }

    public function update(UpdateAdRequest $request, Ad $ad): RedirectResponse
    {
        $data = $this->payload($request);

        if ($request->hasFile('image')) {
            $oldImagePath = $ad->normalizedImagePath();
            $data['image_path'] = $this->storeImage($request);

            if ($oldImagePath && ! str_starts_with($oldImagePath, 'http')) {
                Storage::disk('public')->delete($oldImagePath);
            }
        }

        $ad->update($data);

        return redirect()->route('admin.ads.index')->with('success', 'Ad updated successfully.');
    }

    public function destroy(Ad $ad): RedirectResponse
    {
        $imagePath = $ad->normalizedImagePath();

        if ($imagePath && ! str_starts_with($imagePath, 'http')) {
            Storage::disk('public')->delete($imagePath);
        }

        $ad->delete();

        return redirect()->route('admin.ads.index')->with('success', 'Ad deleted successfully.');
    }

    public function toggleStatus(Ad $ad): RedirectResponse
    {
        $ad->update(['is_active' => ! $ad->is_active]);

        return redirect()->route('admin.ads.index')->with('success', 'Ad status updated successfully.');
    }

    private function payload(StoreAdRequest|UpdateAdRequest $request): array
    {
        $data = $request->validated();

        unset($data['image']);

        $data['is_active'] = $request->boolean('is_active');

        if (! empty($data['starts_at'])) {
            $data['starts_at'] = \Illuminate\Support\Carbon::parse($data['starts_at'], 'Asia/Kolkata')->utc();
        } else {
            $data['starts_at'] = null;
        }

        if (! empty($data['ends_at'])) {
            $data['ends_at'] = \Illuminate\Support\Carbon::parse($data['ends_at'], 'Asia/Kolkata')->utc();
        } else {
            $data['ends_at'] = null;
        }

        return $data;
    }

    private function storeImage(StoreAdRequest|UpdateAdRequest $request): string
    {
        return (string) $request->file('image')->store('ads', 'public');
    }
}
