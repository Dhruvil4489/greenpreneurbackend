<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\BrandPartner;
use App\Models\BrandPartnerCategory;
use App\Models\BrandPartnerClick;
use App\Models\BrandPartnerView;
use App\Models\BrandPartnerSaved;
use App\Services\BrandPartners\BrandPartnerAnalyticsService;
use App\Services\Media\FileUploadService;
use App\Jobs\SendBulkPartnerNotificationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandPartnerApiController extends BaseApiController
{
    public function __construct(
        private readonly BrandPartnerAnalyticsService $analyticsService,
        private readonly FileUploadService $fileUploadService
    ) {
    }

    // -------------------------------------------------------------
    // Member & Public APIs
    // -------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category_id');

        $query = BrandPartner::where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('short_description', 'ILIKE', '%' . $search . '%')
                  ->orWhere('offer_title', 'ILIKE', '%' . $search . '%');
            });
        }

        $partners = $query->orderBy('priority')
            ->orderByDesc('created_at')
            ->paginate(15);

        return $this->success($partners, 'Active brand partners retrieved successfully.');
    }

    public function home(Request $request): JsonResponse
    {
        $categories = BrandPartnerCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->limit(8)
            ->get();

        $featured = BrandPartner::where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $sponsored = BrandPartner::where('is_active', true)
            ->where('is_sponsored', true)
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $this->success([
            'categories' => $categories,
            'featured' => $featured,
            'sponsored' => $sponsored,
        ], 'Brand partners home data retrieved successfully.');
    }

    public function show(string $id): JsonResponse
    {
        $partner = BrandPartner::with('category')->find($id);

        if (!$partner || !$partner->is_active) {
            return $this->error('Brand partner not found.', 404);
        }

        return $this->success($partner, 'Brand partner details retrieved successfully.');
    }

    public function categories(): JsonResponse
    {
        $categories = BrandPartnerCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return $this->success($categories, 'Brand partner categories retrieved successfully.');
    }

    public function view(Request $request, string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $user = auth()->user() ?? auth('admin')->user();
        $userId = $user?->id;
        $ipAddress = $request->ip();
        $sessionId = null;
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (\Throwable $e) {}

        // Skip logging if identical interaction occurred within 24 hours
        $exists = BrandPartnerView::where('brand_partner_id', $partner->id)
            ->where('viewed_at', '>=', now()->subHours(24))
            ->where(function ($query) use ($userId, $ipAddress, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where(function ($sub) use ($ipAddress, $sessionId) {
                        $sub->where('ip_address', $ipAddress);
                        if ($sessionId) {
                            $sub->orWhere('session_id', $sessionId);
                        }
                    });
                }
            })
            ->exists();

        if (!$exists) {
            BrandPartnerView::create([
                'user_id' => $userId,
                'brand_partner_id' => $partner->id,
                'ip_address' => $ipAddress,
                'session_id' => $sessionId,
                'viewed_at' => now(),
            ]);
        }

        return $this->success(null, 'View event logged successfully.');
    }

    public function click(Request $request, string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $clickType = $request->input('click_type', 'website');
        $validTypes = ['visit', 'redeem', 'share', 'call', 'email', 'website'];

        if (!in_array($clickType, $validTypes, true)) {
            return $this->error('Invalid click type. Allowed types: ' . implode(', ', $validTypes), 422);
        }

        $user = auth()->user() ?? auth('admin')->user();
        $userId = $user?->id;
        $ipAddress = $request->ip();
        $sessionId = null;
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (\Throwable $e) {}

        // Skip logging if identical interaction occurred within 24 hours
        $exists = BrandPartnerClick::where('brand_partner_id', $partner->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where(function ($query) use ($userId, $ipAddress, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where(function ($sub) use ($ipAddress, $sessionId) {
                        $sub->where('ip_address', $ipAddress);
                        if ($sessionId) {
                            $sub->orWhere('session_id', $sessionId);
                        }
                    });
                }
            })
            ->exists();

        if (!$exists) {
            BrandPartnerClick::create([
                'user_id' => $userId,
                'brand_partner_id' => $partner->id,
                'click_type' => $clickType,
                'ip' => $ipAddress,
                'ip_address' => $ipAddress,
                'session_id' => $sessionId,
                'device' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        return $this->success(null, 'Click event logged successfully.');
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $userId = auth()->id();

        BrandPartnerSaved::query()->updateOrCreate([
            'user_id' => $userId,
            'brand_partner_id' => $partner->id,
        ]);

        return $this->success(null, 'Brand partner saved successfully.');
    }

    public function unsave(Request $request, string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $userId = auth()->id();

        BrandPartnerSaved::where('user_id', $userId)
            ->where('brand_partner_id', $partner->id)
            ->delete();

        return $this->success(null, 'Brand partner unsaved successfully.');
    }

    public function mySaved(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $savedPartners = BrandPartner::whereHas('saves', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('is_active', true)
        ->orderByDesc('created_at')
        ->paginate(15);

        return $this->success($savedPartners, 'Your saved brand partners.');
    }

    public function featured(): JsonResponse
    {
        $featured = BrandPartner::where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->get();

        return $this->success($featured, 'Featured brand partners.');
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category_id');

        $query = BrandPartner::where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($q !== '') {
            $query->where(function ($query) use ($q) {
                $query->where('name', 'ILIKE', '%' . $q . '%')
                      ->orWhere('short_description', 'ILIKE', '%' . $q . '%')
                      ->orWhere('description', 'ILIKE', '%' . $q . '%');
            });
        }

        $partners = $query->orderBy('priority')->get();

        return $this->success($partners, 'Search results.');
    }

    public function offers(Request $request): JsonResponse
    {
        $now = now();
        $offers = BrandPartner::where('is_active', true)
            ->whereNotNull('offer_title')
            ->where('offer_title', '<>', '')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->orderBy('priority')
            ->get();

        return $this->success($offers, 'Active coupons and offers list.');
    }

    // -------------------------------------------------------------
    // Admin APIs (For Unity Panel)
    // -------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:brand_partners,slug'],
            'logo_file' => ['nullable', 'file', 'image', 'max:5120'],
            'cover_file' => ['nullable', 'file', 'image', 'max:10240'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'uuid', 'exists:brand_partner_categories,id'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'offer_title' => ['nullable', 'string', 'max:255'],
            'offer_description' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'discount_type' => ['nullable', 'string', 'max:50'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
            'is_sponsored' => ['nullable', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'terms_and_conditions' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'keywords' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->hasFile('logo_file')) {
            $logoModel = $this->fileUploadService->store($request->file('logo_file'), null);
            $data['logo'] = $logoModel->s3_key;
        }

        if ($request->hasFile('cover_file')) {
            $coverModel = $this->fileUploadService->store($request->file('cover_file'), null);
            $data['cover_image'] = $coverModel->s3_key;
        }

        $user = auth()->user() ?? auth('admin')->user();
        $data['created_by'] = $user?->id;
        $data['updated_by'] = $user?->id;

        $data['whatsapp'] = $request->input('whatsapp');
        $data['address'] = $request->input('address');
        $data['terms_and_conditions'] = $request->input('terms_and_conditions');
        $data['meta_title'] = $request->input('meta_title');
        $data['meta_description'] = $request->input('meta_description');
        $data['keywords'] = $request->input('keywords');

        $data['priority'] = (int) $request->input('priority', 0);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_featured'] = $request->boolean('is_featured', false);
        $data['is_verified'] = $request->boolean('is_verified', false);
        $data['is_sponsored'] = $request->boolean('is_sponsored', false);

        $partner = BrandPartner::query()->create($data);

        if ($partner->is_active) {
            SendBulkPartnerNotificationJob::dispatch($partner, 'joined');
            if (!empty($partner->offer_title)) {
                SendBulkPartnerNotificationJob::dispatch($partner, 'offer');
            }
        }

        return $this->success($partner, 'Brand partner created.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:brand_partners,slug,' . $partner->id],
            'logo_file' => ['nullable', 'file', 'image', 'max:5120'],
            'cover_file' => ['nullable', 'file', 'image', 'max:10240'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'uuid', 'exists:brand_partner_categories,id'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'offer_title' => ['nullable', 'string', 'max:255'],
            'offer_description' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'discount_type' => ['nullable', 'string', 'max:50'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
            'is_sponsored' => ['nullable', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'terms_and_conditions' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'keywords' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->hasFile('logo_file')) {
            $logoModel = $this->fileUploadService->store($request->file('logo_file'), null);
            $data['logo'] = $logoModel->s3_key;
        }

        if ($request->hasFile('cover_file')) {
            $coverModel = $this->fileUploadService->store($request->file('cover_file'), null);
            $data['cover_image'] = $coverModel->s3_key;
        }

        $user = auth()->user() ?? auth('admin')->user();
        $data['updated_by'] = $user?->id;

        $data['whatsapp'] = $request->input('whatsapp');
        $data['address'] = $request->input('address');
        $data['terms_and_conditions'] = $request->input('terms_and_conditions');
        $data['meta_title'] = $request->input('meta_title');
        $data['meta_description'] = $request->input('meta_description');
        $data['keywords'] = $request->input('keywords');

        $data['priority'] = (int) $request->input('priority', 0);
        $data['is_active'] = $request->boolean('is_active', false);
        $data['is_featured'] = $request->boolean('is_featured', false);
        $data['is_verified'] = $request->boolean('is_verified', false);
        $data['is_sponsored'] = $request->boolean('is_sponsored', false);

        $wasActive = $partner->is_active;
        $hadOffer = !empty($partner->offer_title);

        $partner->update($data);

        if (!$wasActive && $partner->is_active) {
            SendBulkPartnerNotificationJob::dispatch($partner, 'joined');
        }
        if ($partner->is_active && !$hadOffer && !empty($partner->offer_title)) {
            SendBulkPartnerNotificationJob::dispatch($partner, 'offer');
        }

        return $this->success($partner, 'Brand partner updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $partner->delete();

        return $this->success(null, 'Brand partner deleted.');
    }

    public function analytics(): JsonResponse
    {
        $stats = $this->analyticsService->getDashboardStats();
        $charts = $this->analyticsService->getDashboardCharts();

        return $this->success([
            'stats' => $stats,
            'charts' => $charts,
        ], 'Brand partner analytics report.');
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $category = BrandPartnerCategory::create($data);

        return $this->success($category, 'Category created.', 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $category = BrandPartnerCategory::find($id);

        if (!$category) {
            return $this->error('Category not found.', 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $category->update($data);

        return $this->success($category, 'Category updated.');
    }

    public function destroyCategory(string $id): JsonResponse
    {
        $category = BrandPartnerCategory::find($id);

        if (!$category) {
            return $this->error('Category not found.', 404);
        }

        $category->delete();

        return $this->success(null, 'Category deleted.');
    }

    public function toggleStatus(string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $partner->update(['is_active' => !$partner->is_active]);

        return $this->success($partner, 'Brand partner status toggled.');
    }

    public function toggleFeatured(string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $partner->update(['is_featured' => !$partner->is_featured]);

        return $this->success($partner, 'Brand partner featured toggled.');
    }

    public function toggleSponsored(string $id): JsonResponse
    {
        $partner = BrandPartner::find($id);

        if (!$partner) {
            return $this->error('Brand partner not found.', 404);
        }

        $partner->update(['is_sponsored' => !$partner->is_sponsored]);

        return $this->success($partner, 'Brand partner sponsored toggled.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'uuid', 'exists:brand_partners,id'],
        ]);

        foreach ($request->input('order') as $index => $id) {
            BrandPartner::where('id', $id)->update(['priority' => $index]);
        }

        return $this->success(null, 'Partners reordered successfully.');
    }
}
