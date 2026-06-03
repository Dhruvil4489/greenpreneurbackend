<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScanAppUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EventScanCredentialController extends Controller
{
    public function index(Request $request): View
    {
        $credentials = ScanAppUser::query()
            ->with('event')
            ->when($request->search, function ($q, $search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('name', 'ilike', $like)
                        ->orWhere('username', 'ilike', $like)
                        ->orWhere('hotel_name', 'ilike', $like)
                        ->orWhere('event_name', 'ilike', $like);
                });
            })
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.event-scan-credentials.index', compact('credentials'));
    }

    public function create(): View
    {
        return view('admin.event-scan-credentials.form', [
            'credential' => new ScanAppUser(['is_active' => true]),
            'events' => $this->events(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $event = Event::query()->findOrFail($data['event_id']);

        ScanAppUser::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'hotel_name' => $data['hotel_name'],
            'event_id' => $event->id,
            'event_name' => $event->title,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_by_admin_id' => auth('admin')->id(),
        ]);

        return redirect()->route('admin.event-scan-credentials.index')->with('success', 'Scanner credential created successfully.');
    }

    public function edit(ScanAppUser $eventScanCredential): View
    {
        return view('admin.event-scan-credentials.form', [
            'credential' => $eventScanCredential,
            'events' => $this->events(),
        ]);
    }

    public function update(Request $request, ScanAppUser $eventScanCredential): RedirectResponse
    {
        $data = $this->validated($request, $eventScanCredential);
        $event = Event::query()->findOrFail($data['event_id']);

        $updates = [
            'name' => $data['name'],
            'username' => $data['username'],
            'hotel_name' => $data['hotel_name'],
            'event_id' => $event->id,
            'event_name' => $event->title,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];

        if (! empty($data['password'])) {
            $updates['password_hash'] = Hash::make($data['password']);
        }

        $eventScanCredential->update($updates);

        return redirect()->route('admin.event-scan-credentials.index')->with('success', 'Scanner credential updated successfully.');
    }

    public function toggle(ScanAppUser $eventScanCredential): RedirectResponse
    {
        $eventScanCredential->forceFill(['is_active' => ! $eventScanCredential->is_active])->save();

        return back()->with('success', 'Scanner credential status updated successfully.');
    }

    public function resetPassword(Request $request, ScanAppUser $eventScanCredential): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $eventScanCredential->forceFill(['password_hash' => Hash::make($data['password'])])->save();

        return back()->with('success', 'Scanner credential password reset successfully.');
    }

    private function validated(Request $request, ?ScanAppUser $credential = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('scan_app_users', 'username')->ignore($credential?->id)],
            'password' => [$credential ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'hotel_name' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function events()
    {
        return Event::query()->orderByDesc('start_at')->get(['id', 'title', 'start_at', 'location_text']);
    }
}
