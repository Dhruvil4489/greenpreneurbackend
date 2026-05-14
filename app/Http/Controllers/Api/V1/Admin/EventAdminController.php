<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Event\UpsertAdminEventRequest;
use App\Http\Resources\Event\EventDetailResource;
use App\Models\Event;
use App\Services\Events\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventAdminController extends BaseApiController
{
    public function __construct(private readonly EventService $events) {}

    public function index(Request $request): JsonResponse
    {
        $items = Event::query()
            ->with(['circle', 'occurrences'])
            ->withCount(['registrations', 'occurrences'])
            ->when($request->input('event_type'), fn ($q, $v) => $q->where('event_type', $v))
            ->when($request->input('circle_id'), fn ($q, $v) => $q->where('circle_id', $v))
            ->latest('start_at')
            ->paginate(max(1, min((int) $request->input('per_page', 20), 100)));

        return $this->success($items, 'Admin events fetched successfully.');
    }

    public function store(UpsertAdminEventRequest $request): JsonResponse
    {
        $event = $this->events->create($request->validated(), $request->user());

        return $this->success(new EventDetailResource($event), 'Event created successfully.', 201);
    }

    public function show(string $id): JsonResponse
    {
        $event = Event::query()
            ->with(['circle', 'occurrences' => fn ($q) => $q->withCount(['registrations as registered_count' => fn ($r) => $r->where('status', '!=', 'cancelled')])->orderBy('start_at')])
            ->withCount(['registrations', 'occurrences'])
            ->findOrFail($id);

        return $this->success(new EventDetailResource($event), 'Event fetched successfully.');
    }

    public function update(UpsertAdminEventRequest $request, string $id): JsonResponse
    {
        $event = $this->events->update(Event::query()->findOrFail($id), $request->validated());

        return $this->success(new EventDetailResource($event), 'Event updated successfully.');
    }

    public function destroy(string $id): JsonResponse
    {
        Event::query()->findOrFail($id)->delete();

        return $this->success(['deleted' => true], 'Event deleted successfully.');
    }
}
