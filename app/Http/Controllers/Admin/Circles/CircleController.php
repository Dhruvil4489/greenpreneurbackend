<?php

namespace App\Http\Controllers\Admin\Circles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Circles\StoreCircleRequest;
use App\Http\Requests\Admin\Circles\UpdateCircleRequest;
use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\CircleCategory;
use App\Models\City;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use App\Services\IndustryDirector\IndustryScopeService;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CircleController extends Controller
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly IndustryScopeService $industryScope,
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $cityId = trim((string) $request->query('city_id', ''));
        $country = trim((string) $request->query('country', ''));
        $type = trim((string) $request->query('type', ''));
        $status = trim((string) $request->query('status', ''));
        $circleStage = trim((string) $request->query('circle_stage', ''));
        $rank = trim((string) $request->query('rank', ''));

        $filters = [
            'circle_name' => trim((string) $request->query('circle_name', '')),
            'founder' => trim((string) $request->query('founder', '')),
            'city' => trim((string) $request->query('city', '')),
            'city_id' => $cityId,
            'search' => $search,
            'country' => $country,
            'type' => $type,
            'industry_tags' => trim((string) $request->query('industry_tags', '')),
            'meeting_mode' => trim((string) $request->query('meeting_mode', '')),
            'meeting_frequency' => trim((string) $request->query('meeting_frequency', '')),
            'launch_date' => trim((string) $request->query('launch_date', '')),
            'director' => trim((string) $request->query('director', '')),
            'industry_director' => trim((string) $request->query('industry_director', '')),
            'ded' => trim((string) $request->query('ded', '')),
            'status' => $status,
            'circle_stage' => $circleStage,
            'rank' => $rank,
        ];

        $allowedCircleIds = $request->attributes->get('allowed_circle_ids');

        $query = Circle::query()
            ->leftJoin('cities as city', 'city.id', '=', 'circles.city_id')
            ->select([
                'circles.*',
                'city.name as city_name',
                'city.country as city_country',
            ])
            ->with(['founder', 'director', 'industryDirector', 'ded', 'city', 'coverFile'])
            ->withCount('members');

        if (is_array($allowedCircleIds)) {
            if ($allowedCircleIds === []) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('circles.id', $allowedCircleIds);
            }
        }

        $admin = Auth::guard('admin')->user();
        if (AdminAccess::isDed($admin)) {
            AdminCircleScope::applyToCirclesQuery($query, $admin);
        $industryCircleIds = null;
        if ($this->industryScope->isIndustryDirector($admin)) {
            $industryCircleIds = $this->industryScope->circleIdsForAdmin($admin);
            $query->when($industryCircleIds !== [], fn ($circleQuery) => $circleQuery->whereIn('circles.id', $industryCircleIds), fn ($circleQuery) => $circleQuery->whereRaw('1 = 0'));
        }

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery->where('circles.name', 'ILIKE', $like)
                    ->orWhere('circles.slug', 'ILIKE', $like)
                    ->orWhereHas('founder', function ($founderQuery) use ($like): void {
                        $founderQuery->where('display_name', 'ILIKE', $like)
                            ->orWhere('first_name', 'ILIKE', $like)
                            ->orWhere('last_name', 'ILIKE', $like)
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) ILIKE ?", [$like]);
                    });
            });
        }

        if ($filters['circle_name'] !== '') {
            $query->where('circles.name', $filters['circle_name']);
        }

        if ($cityId !== '' && $cityId !== 'any') {
            $query->where('circles.city_id', $cityId);
        } elseif ($filters['city'] !== '') {
            $query->where('city.name', $filters['city']);
        }

        if ($country !== '' && $country !== 'any' && Schema::hasColumn('circles', 'country')) {
            $query->where('circles.country', $country);
        }

        if ($type !== '' && $type !== 'any' && Schema::hasColumn('circles', 'type')) {
            $query->where('circles.type', $type);
        }

        if ($filters['meeting_mode'] !== '') {
            if (Schema::hasColumn('circles', 'meeting_mode')) {
                $query->where('circles.meeting_mode', $filters['meeting_mode']);
            } elseif (Schema::hasColumn('circles', 'calendar')) {
                $query->whereRaw("calendar->'settings'->>'meeting_mode' = ?", [$filters['meeting_mode']]);
            }
        }

        if ($filters['meeting_frequency'] !== '') {
            if (Schema::hasColumn('circles', 'meeting_frequency')) {
                $query->where('circles.meeting_frequency', $filters['meeting_frequency']);
            } elseif (Schema::hasColumn('circles', 'calendar')) {
                $query->whereRaw("calendar->'settings'->>'meeting_frequency' = ?", [$filters['meeting_frequency']]);
            }
        }

        if (
            $circleStage !== ''
            && $circleStage !== 'any'
            && in_array($circleStage, Circle::STAGE_OPTIONS, true)
            && Schema::hasColumn('circles', 'circle_stage')
        ) {
            $query->where('circles.circle_stage', $circleStage);
        }

        if ($rank !== '' && $rank !== 'any' && in_array($rank, Circle::RANK_OPTIONS, true)) {
            $rankRange = Circle::rankRange($rank);

            if (is_array($rankRange)) {
                $max = $rankRange['max'];

                if ($max === null) {
                    $query->has('members', '>=', (int) $rankRange['min']);
                } else {
                    $query->has('members', '>=', (int) $rankRange['min'])
                        ->has('members', '<=', (int) $max);
                }
            }
        }

        if ($status !== '' && $status !== 'any' && in_array($status, Circle::STATUS_OPTIONS, true) && Schema::hasColumn('circles', 'status')) {
            $query->where('circles.status', $status);
        }

        if ($filters['industry_tags'] !== '' && Schema::hasColumn('circles', 'industry_tags')) {
            $query->whereRaw('CAST(industry_tags AS TEXT) ILIKE ?', ['%'.$filters['industry_tags'].'%']);
        }

        if ($filters['launch_date'] !== '') {
            if (Schema::hasColumn('circles', 'launch_date')) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['launch_date'])) {
                    $query->whereDate('circles.launch_date', $filters['launch_date']);
                } else {
                    $query->whereRaw('CAST(circles.launch_date AS TEXT) ILIKE ?', ['%'.$filters['launch_date'].'%']);
                }
            } elseif (Schema::hasColumn('circles', 'calendar')) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['launch_date'])) {
                    $query->whereRaw("(calendar->'settings'->>'launch_date')::date = ?", [$filters['launch_date']]);
                } else {
                    $query->whereRaw("COALESCE(calendar->'settings'->>'launch_date', '') ILIKE ?", ['%'.$filters['launch_date'].'%']);
                }
            }
        }

        if ($filters['founder'] !== '') {
            $this->applyUserNameFilter($query, 'founder', $filters['founder']);
        }

        if ($filters['director'] !== '') {
            $this->applyUserNameFilter($query, 'director', $filters['director']);
        }

        if ($filters['industry_director'] !== '') {
            $this->applyUserNameFilter($query, 'industryDirector', $filters['industry_director']);
        }

        if ($filters['ded'] !== '') {
            $this->applyUserNameFilter($query, 'ded', $filters['ded']);
        }

        $circles = $query
            ->orderByDesc('circles.created_at')
            ->paginate(20)
            ->appends($request->query());

        $circleNames = Circle::query()
            ->when(is_array($industryCircleIds), fn ($circleQuery) => $circleQuery->when($industryCircleIds !== [], fn ($inner) => $inner->whereIn('id', $industryCircleIds), fn ($inner) => $inner->whereRaw('1 = 0')))
            ->whereNotNull('name')
            ->select('name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name');

        $cities = City::query()
            ->whereNotNull('name')
            ->orderBy('name')
            ->get(['id', 'name']);

        $countryOptions = Schema::hasColumn('circles', 'country')
            ? Circle::query()->when(is_array($industryCircleIds), fn ($circleQuery) => $circleQuery->when($industryCircleIds !== [], fn ($inner) => $inner->whereIn('id', $industryCircleIds), fn ($inner) => $inner->whereRaw('1 = 0')))->whereNotNull('country')->select('country')->distinct()->orderBy('country')->pluck('country')
            : City::query()->whereNotNull('country')->select('country')->distinct()->orderBy('country')->pluck('country');

        $typeOptions = Schema::hasColumn('circles', 'type')
            ? Circle::query()->when(is_array($industryCircleIds), fn ($circleQuery) => $circleQuery->when($industryCircleIds !== [], fn ($inner) => $inner->whereIn('id', $industryCircleIds), fn ($inner) => $inner->whereRaw('1 = 0')))->whereNotNull('type')->select('type')->distinct()->orderBy('type')->pluck('type')
            : collect();

        $meetingModeOptions = collect(Circle::MEETING_MODE_OPTIONS);
        $meetingFrequencyOptions = collect(Circle::MEETING_FREQUENCY_OPTIONS);
        $statusOptions = collect(Circle::STATUS_OPTIONS);
        $circleStageOptions = collect(Circle::STAGE_OPTIONS);
        $rankOptions = collect(Circle::RANK_OPTIONS);

        return view('admin.circles.index', [
            'circles' => $circles,
            'filters' => $filters,
            'circleNames' => $circleNames,
            'cities' => $cities,
            'countryOptions' => $countryOptions,
            'typeOptions' => $typeOptions,
            'meetingModeOptions' => $meetingModeOptions,
            'meetingFrequencyOptions' => $meetingFrequencyOptions,
            'statusOptions' => $statusOptions,
            'circleStageOptions' => $circleStageOptions,
            'rankOptions' => $rankOptions,
        ]);
    }

    public function create(Request $request): View
    {
        $defaultFounder = $this->defaultFounderUser();
        $countries = $this->countriesList();
        $selectedCountry = $request->input('country', $countries->first() ?? 'India');

        $cities = City::query()
            ->when($selectedCountry, fn ($query) => $query->where('country', $selectedCountry))
            ->orderBy('name')
            ->get();

        return view('admin.circles.create', [
            'circle' => new Circle(),
            'countries' => $countries,
            'selectedCountry' => $selectedCountry,
            'cities' => $cities,
            'statuses' => Circle::STATUS_OPTIONS,
            'types' => Circle::TYPE_OPTIONS,
            'defaultFounder' => $defaultFounder,
            'defaultFounderLabel' => $this->founderLabel($defaultFounder),
            'meetingModes' => Circle::MEETING_MODE_OPTIONS,
            'meetingFrequencies' => Circle::MEETING_FREQUENCY_OPTIONS,
            'allUsers' => $this->allUsers(),
            'circlePackages' => $this->circlePackageOptions(),
            'circleStages' => Circle::STAGE_OPTIONS,
            'categories' => $this->categoriesList(),
            'selectedCategoryIds' => old('categories', []),
            'categoryFeatureEnabled' => $this->categoryFeatureEnabled(),
        ]);
    }

    public function store(StoreCircleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $circlePackage = $this->resolveCirclePackage($validated['circle_package'] ?? null);
        $baseSlug = Str::slug((string) ($validated['name'] ?? ''));

        if ($baseSlug === '') {
            $baseSlug = 'circle';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (Circle::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $payload = [
            'name' => $validated['name'] ?? null,
            'slug' => $slug,
            'type' => $validated['type'] ?? null,
            'status' => $validated['status'] ?? null,
            'city_id' => $validated['city_id'] ?? null,
            'country' => $validated['country'] ?? null,
            'description' => $validated['description'] ?? null,
            'purpose' => $validated['purpose'] ?? null,
            'announcement' => $validated['announcement'] ?? null,
            'industry_tags' => $this->normalizeIndustryTags($validated['industry_tags'] ?? null),
            'circle_stage' => $validated['circle_stage'] ?? null,
        ];

        if (empty($payload['status'])) {
            unset($payload['status']);
        }

        $payload = array_merge($payload, $this->buildCirclePackagePayload($circlePackage));
        $payload = $this->pruneEmptyPayload($payload);

        $circle = new Circle();
        $circle->forceFill($this->filterCircleDataByExistingColumns($payload));

        $calendar = is_array($circle->calendar) ? $circle->calendar : [];
        $calendar = $this->mergeCalendarSettings($calendar, $validated, $request);
        $circle->calendar = $calendar;

        $circle->save();

        if ($this->categoryFeatureEnabled() && method_exists($circle, 'categories')) {
            $categoryIds = collect($validated['categories'] ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->take(1)
                ->values()
                ->all();

            $circle->categories()->sync($categoryIds);
        }

        $circle->refresh();

        Cache::forget('admin.circles.index');
        Cache::forget('admin.circles.filters');

        return redirect()
            ->route('admin.circles.show', $circle)
            ->with('success', 'Circle created successfully.');
    }

    public function show(Request $request, Circle $circle): View
    {
        $allowedCircleIds = $request->attributes->get('allowed_circle_ids');

        if (is_array($allowedCircleIds) && ! in_array($circle->id, $allowedCircleIds, true)) {
            abort(403);
        }

        if (! $this->industryScope->circleInScope(Auth::guard('admin')->user(), (string) $circle->id)) {
            abort(403);
        }

        $validatedFilters = $request->validate([
            'peer_name' => ['nullable', 'string', 'max:120'],
            'peer_email' => ['nullable', 'string', 'max:190'],
        ]);

        $peerFilters = [
            'peer_name' => $this->sanitizeFilterInput($validatedFilters['peer_name'] ?? ''),
            'peer_email' => $this->sanitizeFilterInput($validatedFilters['peer_email'] ?? ''),
        ];

        $relations = ['city', 'founder', 'director', 'industryDirector', 'ded'];

        if ($this->categoryFeatureEnabled() && method_exists($circle, 'categories')) {
            $relations[] = 'categories';
        }

        $circle->load($relations);

        $calendar = is_array($circle->calendar) ? $circle->calendar : [];

        $circleStage = trim((string) $circle->getRawOriginal('circle_stage'));
        if ($circleStage === '') {
            $circleStage = trim((string) data_get($calendar, 'settings.circle_stage', ''));
        }

        $rawMeetingSchedule = data_get($calendar, 'meeting_schedule');
        $meetings = is_array($rawMeetingSchedule) ? array_values($rawMeetingSchedule) : [];
        $timezone = data_get($calendar, 'timezone', 'Asia/Kolkata');

        $meetingRows = array_map(function ($meeting, int $index): array {
            $meeting = is_array($meeting) ? $meeting : [];

            return [
                'label' => 'Meeting #'.($index + 1),
                'value' => $this->formatMeetingScheduleEntry($meeting),
            ];
        }, $meetings, array_keys($meetings));

        $peerMembers = $circle->members()
            ->with(['user', 'roleRef'])
            ->when($peerFilters['peer_name'] !== '', function ($query) use ($peerFilters): void {
                $like = '%'.$peerFilters['peer_name'].'%';

                $query->whereHas('user', function ($userQuery) use ($like): void {
                    $userQuery->where(function ($nameQuery) use ($like): void {
                        $nameQuery->where('display_name', 'ILIKE', $like)
                            ->orWhere('first_name', 'ILIKE', $like)
                            ->orWhere('last_name', 'ILIKE', $like)
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) ILIKE ?", [$like]);
                    });
                });
            })
            ->when($peerFilters['peer_email'] !== '', function ($query) use ($peerFilters): void {
                $like = '%'.$peerFilters['peer_email'].'%';
                $query->whereHas('user', fn ($userQuery) => $userQuery->where('email', 'ILIKE', $like));
            })
            ->orderByDesc('joined_at')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->appends($peerFilters);

        return view('admin.circles.show', [
            'circle' => $circle,
            'circleStage' => $circleStage,
            'allUsers' => $this->allUsers(),
            'roles' => CircleMember::roleOptions(),
            'meetingRows' => $meetingRows,
            'timezone' => is_string($timezone) && trim($timezone) !== '' ? trim($timezone) : 'Asia/Kolkata',
            'rankingData' => $circle->getCircleRanking(),
            'categoryFeatureEnabled' => $this->categoryFeatureEnabled(),
            'peerMembers' => $peerMembers,
            'peerFilters' => $peerFilters,
        ]);
    }

    public function edit(Request $request, Circle $circle): View
    {
        $relations = ['city'];

        if ($this->categoryFeatureEnabled() && method_exists($circle, 'categories')) {
            $relations[] = 'categories';
        }

        $circle->load($relations);

        $defaultFounder = $circle->founder ?? $this->defaultFounderUser();
        $countries = $this->countriesList();
        $selectedCountry = $request->input('country', $circle->country ?? $circle->city?->country ?? $countries->first() ?? 'India');

        $cities = City::query()
            ->when($selectedCountry, fn ($query) => $query->where('country', $selectedCountry))
            ->orderBy('name')
            ->get();

        return view('admin.circles.edit', [
            'circle' => $circle,
            'countries' => $countries,
            'selectedCountry' => $selectedCountry,
            'cities' => $cities,
            'statuses' => Circle::STATUS_OPTIONS,
            'types' => Circle::TYPE_OPTIONS,
            'defaultFounder' => $defaultFounder,
            'defaultFounderLabel' => $this->founderLabel($defaultFounder),
            'meetingModes' => Circle::MEETING_MODE_OPTIONS,
            'meetingFrequencies' => Circle::MEETING_FREQUENCY_OPTIONS,
            'allUsers' => $this->allUsers(),
            'circlePackages' => $this->circlePackageOptions(),
            'circleStages' => Circle::STAGE_OPTIONS,
            'categories' => $this->categoriesList(),
            'selectedCategoryIds' => old(
                'categories',
                ($this->categoryFeatureEnabled() && method_exists($circle, 'categories'))
                    ? $circle->categories->pluck('id')->all()
                    : []
            ),
            'categoryFeatureEnabled' => $this->categoryFeatureEnabled(),
        ]);
    }

    public function update(UpdateCircleRequest $request, Circle $circle): RedirectResponse
    {
        $validated = $request->validated();
        $circlePackage = $this->resolveCirclePackage($validated['circle_package'] ?? null);

        $allowed = [];
        foreach ([
            'name',
            'city',
            'country',
            'type',
            'status',
            'industry_tags',
            'founder_user_id',
            'city_id',
            'description',
            'purpose',
            'announcement',
            'circle_stage',
        ] as $column) {
            if (Schema::hasColumn('circles', $column) && array_key_exists($column, $validated)) {
                $allowed[$column] = $validated[$column];
            }
        }

        if (Schema::hasColumn('circles', 'industry_tags')) {
            $allowed['industry_tags'] = $this->normalizeIndustryTags($validated['industry_tags'] ?? null);
        }

        $allowed = array_merge($allowed, $this->buildCirclePackagePayload($circlePackage));
        $allowed = $this->pruneEmptyPayload($allowed);

        if (Schema::hasColumn('circles', 'country') && ! array_key_exists('country', $allowed)) {
            $allowed['country'] = $circle->country;
        }

        $originalName = $circle->name;

        $circle->fill($allowed);

        $calendar = is_array($circle->calendar) ? $circle->calendar : [];
        $calendar = $this->mergeCalendarSettings($calendar, $validated, $request);
        $circle->calendar = $calendar;

        if (array_key_exists('name', $allowed) && $circle->name !== $originalName) {
            $circle->slug = Circle::generateUniqueSlug($circle->name, $circle->id);
        }

        $circle->save();

        if ($this->categoryFeatureEnabled() && method_exists($circle, 'categories')) {
            $categoryIds = collect($validated['categories'] ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->take(1)
                ->values()
                ->all();

            $circle->categories()->sync($categoryIds);
        }

        $circle->refresh();

        Cache::forget('admin.circles.index');
        Cache::forget('admin.circles.filters');

        return redirect()
            ->route('admin.circles.show', $circle)
            ->with('success', 'Circle updated successfully.');
    }

    public function destroy(Circle $circle): RedirectResponse
    {
        $circle->delete();

        return redirect()
            ->route('admin.circles.index')
            ->with('success', 'Circle deleted successfully.');
    }

    private function applyUserNameFilter($query, string $relation, string $search): void
    {
        $query->whereHas($relation, function ($userQuery) use ($search): void {
            $like = '%'.$search.'%';

            $userQuery->where(function ($nameQuery) use ($like): void {
                $nameQuery->where('display_name', 'ILIKE', $like)
                    ->orWhere('first_name', 'ILIKE', $like)
                    ->orWhere('last_name', 'ILIKE', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) ILIKE ?", [$like]);
            });
        });
    }

    private function normalizeIndustryTags(null|string|array $tags): ?array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map('trim', $tags)));
        }

        if (is_string($tags)) {
            $trimmed = array_filter(array_map('trim', explode(',', $tags)));
            return $trimmed ? array_values($trimmed) : null;
        }

        return null;
    }

    private function formatMeetingScheduleEntry(array $meeting): string
    {
        $frequency = strtolower(trim((string) ($meeting['frequency'] ?? '')));
        $day = trim((string) ($meeting['day_of_week'] ?? ''));
        $time = trim((string) ($meeting['default_meet_time'] ?? ''));

        if ($frequency === '' || $day === '' || $time === '') {
            return '—';
        }

        return $day.' at '.$time.' ('.ucfirst($frequency).')';
    }

    private function normalizeCalendarMeetings(mixed $meetings): ?array
    {
        if (! is_array($meetings)) {
            return null;
        }

        $normalized = [];

        foreach ($meetings as $meeting) {
            if (! is_array($meeting)) {
                continue;
            }

            $frequency = strtolower(trim((string) ($meeting['frequency'] ?? '')));
            $day = strtolower(trim((string) ($meeting['default_meet_day'] ?? '')));
            $time = trim((string) ($meeting['default_meet_time'] ?? ''));
            $monthlyRule = strtolower(trim((string) ($meeting['monthly_rule'] ?? '')));

            if ($frequency === '' && $day === '' && $time === '' && $monthlyRule === '') {
                continue;
            }

            if (! in_array($frequency, ['weekly', 'monthly', 'quarterly'], true)) {
                continue;
            }

            if ($day === '' || $time === '') {
                continue;
            }

            $row = [
                'frequency' => $frequency,
                'default_meet_day' => $day,
                'default_meet_time' => $time,
            ];

            if (in_array($frequency, ['monthly', 'quarterly'], true) && $monthlyRule !== '') {
                $row['monthly_rule'] = $monthlyRule;
            }

            $normalized[] = $row;
        }

        if ($normalized === []) {
            return null;
        }

        $payload = [
            'timezone' => 'Asia/Kolkata',
            'meetings' => array_values($normalized),
        ];

        $first = $payload['meetings'][0];
        $payload['frequency'] = $first['frequency'];
        $payload['default_meet_day'] = $first['default_meet_day'];
        $payload['default_meet_time'] = $first['default_meet_time'];

        if (isset($first['monthly_rule'])) {
            $payload['monthly_rule'] = $first['monthly_rule'];
        }

        return $payload;
    }

    private function circlePackageOptions(): array
    {
        try {
            return $this->zohoBillingService->listCirclePackageAddons(true);
        } catch (Throwable $throwable) {
            report($throwable);
            session()->flash('error', 'Unable to load Circle Package addons from Zoho Billing right now.');

            return [];
        }
    }

    private function resolveCirclePackage(?string $selection): ?array
    {
        $selection = trim((string) $selection);

        if ($selection === '') {
            return null;
        }

        try {
            $addon = $this->zohoBillingService->findCirclePackageAddonByCodeOrId($selection, true);
        } catch (Throwable $throwable) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'circle_package' => 'Failed to fetch selected Circle Package from Zoho Billing.',
            ]);
        }

        if (! is_array($addon)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'circle_package' => 'Selected Circle Package is invalid or inactive.',
            ]);
        }

        return $addon;
    }

    private function buildCirclePackagePayload(?array $circlePackage): array
    {
        if (! is_array($circlePackage)) {
            return [
                'zoho_addon_code' => null,
                'zoho_addon_id' => null,
                'zoho_addon_name' => null,
                'circle_price_amount' => null,
                'circle_price_currency' => null,
                'circle_duration_months' => null,
            ];
        }

        return [
            'zoho_addon_code' => $circlePackage['addon_code'] ?? null,
            'zoho_addon_id' => $circlePackage['addon_id'] ?? null,
            'zoho_addon_name' => $circlePackage['name'] ?? null,
            'circle_price_amount' => $circlePackage['amount'] ?? null,
            'circle_price_currency' => $circlePackage['currency_code'] ?? null,
            'circle_duration_months' => 12,
        ];
    }

    private function countriesList()
    {
        $countries = City::query()
            ->select('country')
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        if (! $countries->contains('India')) {
            $countries->prepend('India');
        }

        return $countries->unique()->values();
    }

    private function defaultFounderUser(): ?User
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return null;
        }

        return User::query()
            ->where('email', $admin->email)
            ->with(['circleMembers' => function ($query) {
                $query->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->orderByDesc('joined_at')
                    ->with(['circle:id,name']);
            }])
            ->first();
    }

    private function mergeCalendarSettings(array $calendar, array $validated, Request $request): array
    {
        $meetingMode = trim((string) ($validated['meeting_mode'] ?? ''));
        data_set($calendar, 'settings.meeting_mode', $meetingMode !== '' ? strtolower($meetingMode) : null);

        $meetingFrequency = trim((string) ($validated['meeting_frequency'] ?? ''));
        data_set($calendar, 'settings.meeting_frequency', $meetingFrequency !== '' ? strtolower($meetingFrequency) : null);

        $launchDate = trim((string) ($validated['launch_date'] ?? ''));
        data_set($calendar, 'settings.launch_date', $launchDate !== '' ? $launchDate : null);

        data_set($calendar, 'settings.meeting_repeat', $validated['meeting_repeat'] ?? null);

        data_set($calendar, 'leadership.director_user_id', $validated['director_user_id'] ?? null);
        data_set($calendar, 'leadership.industry_director_user_id', $validated['industry_director_user_id'] ?? null);
        data_set($calendar, 'leadership.ded_user_id', $validated['ded_user_id'] ?? null);

        $coverFileId = trim((string) ($validated['cover_file_id'] ?? ''));
        data_set($calendar, 'cover.file_id', $coverFileId !== '' ? $coverFileId : null);

        $freqRows = $request->input('meeting_schedule_frequency', []);
        $timeRows = $request->input('meeting_schedule_default_meet_time', []);
        $dayRows = $request->input('meeting_schedule_day_of_week', []);

        $schedule = [];
        $max = max(count($freqRows), count($timeRows), count($dayRows));
        for ($i = 0; $i < $max; $i++) {
            $freq = strtolower(trim((string) ($freqRows[$i] ?? '')));
            $time = trim((string) ($timeRows[$i] ?? ''));
            $day = trim((string) ($dayRows[$i] ?? ''));

            if ($freq === '' && $time === '' && $day === '') {
                continue;
            }

            if ($freq === '' || $time === '' || $day === '') {
                continue;
            }

            $schedule[] = [
                'frequency' => $freq,
                'default_meet_time' => $time,
                'day_of_week' => $day,
            ];
        }

        data_set($calendar, 'meeting_schedule', $schedule);

        return $calendar;
    }

    private function pruneEmptyPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function filterCircleDataByExistingColumns(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (Schema::hasColumn('circles', $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function allUsers()
    {
        $columns = ['id', 'display_name', 'first_name', 'last_name'];

        foreach (['company_name', 'business_name', 'city'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        return User::query()
            ->whereNull('deleted_at')
            ->with(['circleMembers' => function ($query) {
                $query->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->orderByDesc('joined_at')
                    ->with(['circle:id,name']);
            }])
            ->orderByRaw("COALESCE(NULLIF(display_name, ''), NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), '')) ASC")
            ->limit(2000)
            ->get($columns);
    }

    private function founderLabel(?User $user): string
    {
        if (! $user) {
            return '';
        }

        return $user->adminFounderDropdownLabel();
    }

    private function sanitizeFilterInput(mixed $value): string
    {
        return trim((string) $value);
    }

    private function categoryFeatureEnabled(): bool
    {
        return class_exists(CircleCategory::class)
            && Schema::hasTable('circle_categories')
            && Schema::hasTable('circle_category_mappings');
    }

    private function categoriesList()
    {
        if (! $this->categoryFeatureEnabled()) {
            return collect();
        }

        return CircleCategory::query()
            ->where('level', 1)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'circle_key', 'level']);
    }
}
