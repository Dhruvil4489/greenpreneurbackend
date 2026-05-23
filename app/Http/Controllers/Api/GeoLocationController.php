<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\GeoNearbyPeerResource;
use App\Models\Connection;
use App\Models\User;
use App\Models\UserGeoLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoLocationController extends BaseApiController
{
    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $location = UserGeoLocation::query()->firstOrNew([
            'user_id' => (string) $request->user()->id,
        ]);

        $isNewLocation = ! $location->exists;

        $location->fill([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'last_seen_at' => now(),
        ]);

        if ($isNewLocation) {
            $location->is_visible = true;
        }

        $location->save();

        return $this->success([
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'is_visible' => (bool) $location->is_visible,
            'last_seen_at' => $location->last_seen_at,
        ], 'Location updated successfully.');
    }

    public function updateVisibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_visible' => ['required', 'boolean'],
        ]);

        $location = UserGeoLocation::query()
            ->where('user_id', (string) $request->user()->id)
            ->first();

        if (! $location) {
            return $this->error(
                'Please update your location first.',
                422,
                ['location' => ['Please update your location first.']]
            );
        }

        $location->update([
            'is_visible' => $validated['is_visible'],
        ]);

        return $this->success([
            'is_visible' => (bool) $location->is_visible,
        ], 'Geo visibility updated successfully.');
    }

    public function nearbyPeers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'radius_km' => ['nullable', 'numeric', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $radiusKm = array_key_exists('radius_km', $validated) && $validated['radius_km'] !== null && $validated['radius_km'] !== ''
            ? (float) $validated['radius_km']
            : null;
        $limit = array_key_exists('limit', $validated) && $validated['limit'] !== null && $validated['limit'] !== ''
            ? (int) $validated['limit']
            : null;
        $authUser = $request->user();

        $myLocation = UserGeoLocation::query()
            ->where('user_id', (string) $authUser->id)
            ->first();

        if (! $myLocation) {
            return $this->error(
                'Please update your location first.',
                422,
                ['location' => ['Please update your location first.']]
            );
        }

        $distanceExpression = $this->distanceExpression();
        $distanceBindings = [
            $myLocation->latitude,
            $myLocation->longitude,
            $myLocation->latitude,
        ];

        $peers = User::query()
            ->with('cityRelation:id,name')
            ->join('user_geo_locations', 'user_geo_locations.user_id', '=', 'users.id')
            ->where('user_geo_locations.is_visible', true)
            ->where('users.id', '!=', (string) $authUser->id)
            ->select([
                'users.id',
                'users.display_name',
                'users.first_name',
                'users.last_name',
                'users.company_name',
                'users.designation',
                'users.business_type',
                'users.profile_photo_file_id',
                'users.profile_photo_url',
                'users.city_id',
                'users.city',
                'users.life_impacted_count',
            ])
            ->selectRaw('user_geo_locations.last_seen_at as geo_last_seen_at')
            ->selectRaw('user_geo_locations.latitude as geo_latitude, user_geo_locations.longitude as geo_longitude')
            ->selectRaw($distanceExpression . ' as distance_km', $distanceBindings)
            ->when($radiusKm !== null, function ($query) use ($distanceExpression, $distanceBindings, $radiusKm) {
                $query->whereRaw($distanceExpression . ' <= ?', [...$distanceBindings, $radiusKm]);
            })
            ->orderBy('distance_km', 'asc')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        $this->attachConnectionState($peers, (string) $authUser->id);

        return $this->success([
            'radius_km' => $radiusKm,
            'total' => $peers->count(),
            'items' => GeoNearbyPeerResource::collection($peers),
        ], 'Nearby peers fetched successfully.');
    }

    private function distanceExpression(): string
    {
        return '6371 * acos(LEAST(1, GREATEST(-1, '
            . 'cos(radians(?)) * cos(radians(user_geo_locations.latitude)) * '
            . 'cos(radians(user_geo_locations.longitude) - radians(?)) + '
            . 'sin(radians(?)) * sin(radians(user_geo_locations.latitude))'
            . ')))';
    }

    private function attachConnectionState($peers, string $authUserId): void
    {
        if ($peers->isEmpty()) {
            return;
        }

        $peerIds = $peers->pluck('id')->map(fn ($id) => (string) $id)->all();

        $connections = Connection::query()
            ->where(function ($query) use ($authUserId, $peerIds) {
                $query->where('requester_id', $authUserId)
                    ->whereIn('addressee_id', $peerIds);
            })
            ->orWhere(function ($query) use ($authUserId, $peerIds) {
                $query->whereIn('requester_id', $peerIds)
                    ->where('addressee_id', $authUserId);
            })
            ->get()
            ->keyBy(function (Connection $connection) use ($authUserId) {
                return (string) ($connection->requester_id === $authUserId
                    ? $connection->addressee_id
                    : $connection->requester_id);
            });

        $peers->each(function (User $peer) use ($connections, $authUserId): void {
            $connection = $connections->get((string) $peer->id);

            $peer->setAttribute('connection_status', null);
            $peer->setAttribute('can_send_connection_request', true);

            if (! $connection) {
                return;
            }

            $status = $connection->is_approved
                ? 'connected'
                : ($connection->requester_id === $authUserId ? 'pending_sent' : 'pending_received');

            $peer->setAttribute('connection_status', $status);
            $peer->setAttribute('can_send_connection_request', false);
        });
    }
}
