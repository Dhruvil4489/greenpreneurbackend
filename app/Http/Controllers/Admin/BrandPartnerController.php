<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandPartners\StoreBrandPartnerRequest;
use App\Http\Requests\Admin\BrandPartners\UpdateBrandPartnerRequest;
use App\Models\BrandPartner;
use App\Models\BrandPartnerCategory;
use App\Services\Media\FileUploadService;
use App\Services\BrandPartners\BrandPartnerAnalyticsService;
use App\Jobs\SendBulkPartnerNotificationJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BrandPartnerController extends Controller
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly BrandPartnerAnalyticsService $analyticsService
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $featured = $request->query('featured');
        $sponsored = $request->query('sponsored');
        $categoryId = $request->query('category_id');
        $hasOffer = $request->query('has_offer');

        $query = BrandPartner::query()
            ->with('category')
            ->withCount(['views', 'clicks']);

        // Search support
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('slug', 'ILIKE', '%' . $search . '%')
                  ->orWhere('offer_title', 'ILIKE', '%' . $search . '%');
            });
        }

        // Filters support
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($featured === '1') {
            $query->where('is_featured', true);
        }

        if ($sponsored === '1') {
            $query->where('is_sponsored', true);
        }

        if ($categoryId && $categoryId !== '') {
            $query->where('category_id', $categoryId);
        }

        if ($hasOffer === '1') {
            $query->whereNotNull('offer_title')->where('offer_title', '<>', '');
        }

        $partners = $query->orderBy('priority')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        $categories = BrandPartnerCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return view('admin.brand-partners.index', compact('partners', 'categories', 'search', 'status', 'featured', 'sponsored', 'categoryId', 'hasOffer'));
    }

    public function create(): View
    {
        $categories = BrandPartnerCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return view('admin.brand-partners.create', [
            'partner' => new BrandPartner(['is_active' => true]),
            'categories' => $categories,
        ]);
    }

    public function store(StoreBrandPartnerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Image uploads reusing existing FileUploadService
        if ($request->hasFile('logo')) {
            $logoModel = $this->fileUploadService->store($request->file('logo'), null);
            $data['logo'] = $logoModel->s3_key;
        }

        if ($request->hasFile('cover_image')) {
            $coverModel = $this->fileUploadService->store($request->file('cover_image'), null);
            $data['cover_image'] = $coverModel->s3_key;
        }

        // Track who created it
        $adminUser = Auth::guard('admin')->user();
        $data['created_by'] = $adminUser?->id;
        $data['updated_by'] = $adminUser?->id;

        // Assign explicit columns
        $data['whatsapp'] = $request->input('whatsapp');
        $data['address'] = $request->input('address');
        $data['terms_and_conditions'] = $request->input('terms_and_conditions');
        $data['meta_title'] = $request->input('meta_title');
        $data['meta_description'] = $request->input('meta_description');
        $data['keywords'] = $request->input('keywords');

        // Normalize non-nullable fields to avoid SQL Integrity Constraint Violations
        $data['priority'] = (int) $request->input('priority', 0);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_featured'] = $request->boolean('is_featured', false);
        $data['is_verified'] = $request->boolean('is_verified', false);
        $data['is_sponsored'] = $request->boolean('is_sponsored', false);

        try {
            $partner = BrandPartner::query()->create($data);
            
            // Dispatch notifications if active
            if ($partner->is_active) {
                SendBulkPartnerNotificationJob::dispatch($partner, 'joined');
                if (!empty($partner->offer_title)) {
                    SendBulkPartnerNotificationJob::dispatch($partner, 'offer');
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create brand partner: ' . $e->getMessage());
            return redirect()->back()
                ->withInput()
                ->withErrors(['database' => 'Failed to save brand partner. Database error: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.brand-partners.index')->with('success', 'Brand partner created successfully.');
    }

    public function show(BrandPartner $brand_partner): View
    {
        $analytics = $this->analyticsService->getPartnerAnalytics($brand_partner->id);
        return view('admin.brand-partners.show', compact('brand_partner', 'analytics'));
    }

    public function edit(BrandPartner $brand_partner): View
    {
        $categories = BrandPartnerCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return view('admin.brand-partners.edit', [
            'partner' => $brand_partner,
            'categories' => $categories,
        ]);
    }

    public function update(UpdateBrandPartnerRequest $request, BrandPartner $brand_partner): RedirectResponse
    {
        $data = $request->validated();

        // Image uploads reusing existing FileUploadService
        if ($request->hasFile('logo')) {
            $logoModel = $this->fileUploadService->store($request->file('logo'), null);
            $data['logo'] = $logoModel->s3_key;
        }

        if ($request->hasFile('cover_image')) {
            $coverModel = $this->fileUploadService->store($request->file('cover_image'), null);
            $data['cover_image'] = $coverModel->s3_key;
        }

        // Track who updated it
        $adminUser = Auth::guard('admin')->user();
        $data['updated_by'] = $adminUser?->id;

        // Assign explicit columns
        $data['whatsapp'] = $request->input('whatsapp');
        $data['address'] = $request->input('address');
        $data['terms_and_conditions'] = $request->input('terms_and_conditions');
        $data['meta_title'] = $request->input('meta_title');
        $data['meta_description'] = $request->input('meta_description');
        $data['keywords'] = $request->input('keywords');

        // Normalize non-nullable fields to avoid SQL Integrity Constraint Violations
        $data['priority'] = (int) $request->input('priority', 0);
        $data['is_active'] = $request->boolean('is_active', false);
        $data['is_featured'] = $request->boolean('is_featured', false);
        $data['is_verified'] = $request->boolean('is_verified', false);
        $data['is_sponsored'] = $request->boolean('is_sponsored', false);

        $wasActive = $brand_partner->is_active;
        $hadOffer = !empty($brand_partner->offer_title);

        try {
            $brand_partner->update($data);

            // Dispatch notifications depending on transitions
            if (!$wasActive && $brand_partner->is_active) {
                SendBulkPartnerNotificationJob::dispatch($brand_partner, 'joined');
            }
            if ($brand_partner->is_active && !$hadOffer && !empty($brand_partner->offer_title)) {
                SendBulkPartnerNotificationJob::dispatch($brand_partner, 'offer');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update brand partner: ' . $e->getMessage());
            return redirect()->back()
                ->withInput()
                ->withErrors(['database' => 'Failed to update brand partner. Database error: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.brand-partners.index')->with('success', 'Brand partner updated successfully.');
    }

    public function destroy(BrandPartner $brand_partner): RedirectResponse
    {
        $brand_partner->delete();

        return redirect()->route('admin.brand-partners.index')->with('success', 'Brand partner deleted successfully.');
    }

    public function duplicate(BrandPartner $brand_partner): RedirectResponse
    {
        $newPartner = $brand_partner->replicate();
        $newPartner->name = $brand_partner->name . ' (Copy)';
        $newPartner->slug = $brand_partner->slug . '-' . Str::lower(Str::random(4));
        $newPartner->uuid = (string) Str::uuid();
        $newPartner->is_active = false;
        $newPartner->save();

        return redirect()->route('admin.brand-partners.index')->with('success', 'Brand partner duplicated successfully as draft.');
    }

    public function toggleStatus(BrandPartner $brand_partner): RedirectResponse
    {
        $brand_partner->update(['is_active' => ! $brand_partner->is_active]);

        return redirect()->back()->with('success', 'Brand partner status updated.');
    }

    public function toggleFeatured(BrandPartner $brand_partner): RedirectResponse
    {
        $brand_partner->update(['is_featured' => ! $brand_partner->is_featured]);

        return redirect()->back()->with('success', 'Brand partner featured setting updated.');
    }

    public function toggleSponsored(BrandPartner $brand_partner): RedirectResponse
    {
        $brand_partner->update(['is_sponsored' => ! $brand_partner->is_sponsored]);

        return redirect()->back()->with('success', 'Brand partner sponsored setting updated.');
    }

    public function reorderPriority(Request $request): RedirectResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'uuid', 'exists:brand_partners,id'],
        ]);

        foreach ($request->input('order') as $index => $id) {
            BrandPartner::where('id', $id)->update(['priority' => $index]);
        }

        return redirect()->back()->with('success', 'Priorities reordered.');
    }

    public function offers(Request $request): View
    {
        $now = now();
        $activeOffers = BrandPartner::where('is_active', true)
            ->whereNotNull('offer_title')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->with('category')
            ->orderBy('priority')
            ->get();

        $expiredOffers = BrandPartner::whereNotNull('offer_title')
            ->where('valid_to', '<', $now)
            ->with('category')
            ->orderByDesc('valid_to')
            ->get();

        return view('admin.brand-partners.offers', compact('activeOffers', 'expiredOffers'));
    }

    public function export(Request $request)
    {
        $format = $request->query('format', 'csv');
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $featured = $request->query('featured');
        $sponsored = $request->query('sponsored');
        $categoryId = $request->query('category_id');

        $query = BrandPartner::query()->with('category');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('slug', 'ILIKE', '%' . $search . '%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($featured === '1') {
            $query->where('is_featured', true);
        }

        if ($sponsored === '1') {
            $query->where('is_sponsored', true);
        }

        if ($categoryId && $categoryId !== '') {
            $query->where('category_id', $categoryId);
        }

        $partners = $query->orderBy('priority')->get();

        if ($format === 'pdf') {
            return view('admin.brand-partners.pdf_export', compact('partners'));
        }

        // CSV/Excel streamed export
        $filename = 'brand_partners_export_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($partners): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Could not open output stream for CSV export.');
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Name', 'Slug', 'Category', 'Featured', 'Sponsored', 'Offer Title', 'Coupon Code', 'Status', 'Created At']);

            foreach ($partners as $partner) {
                fputcsv($handle, [
                    $partner->id,
                    $partner->name,
                    $partner->slug,
                    $partner->category?->name ?? '—',
                    $partner->is_featured ? 'Yes' : 'No',
                    $partner->is_sponsored ? 'Yes' : 'No',
                    $partner->offer_title ?? '—',
                    $partner->coupon_code ?? '—',
                    $partner->is_active ? 'Active' : 'Inactive',
                    $partner->created_at->format('Y-m-d H:i'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function movePriorityUp(BrandPartner $brand_partner): RedirectResponse
    {
        $partners = BrandPartner::orderBy('priority')->orderBy('created_at', 'desc')->get();
        $index = $partners->pluck('id')->search($brand_partner->id);

        if ($index !== false && $index > 0) {
            $previousPartner = $partners->get($index - 1);
            
            $temp = $brand_partner->priority;
            $brand_partner->priority = $previousPartner->priority;
            $previousPartner->priority = $temp;

            if ($brand_partner->priority == $previousPartner->priority) {
                $brand_partner->priority = max(0, $brand_partner->priority - 1);
            }

            $brand_partner->save();
            $previousPartner->save();

            return redirect()->back()->with('success', 'Priority moved up.');
        }

        return redirect()->back()->with('info', 'Brand partner is already at the top.');
    }

    public function movePriorityDown(BrandPartner $brand_partner): RedirectResponse
    {
        $partners = BrandPartner::orderBy('priority')->orderBy('created_at', 'desc')->get();
        $index = $partners->pluck('id')->search($brand_partner->id);

        if ($index !== false && $index < ($partners->count() - 1)) {
            $nextPartner = $partners->get($index + 1);

            $temp = $brand_partner->priority;
            $brand_partner->priority = $nextPartner->priority;
            $nextPartner->priority = $temp;

            if ($brand_partner->priority == $nextPartner->priority) {
                $brand_partner->priority = $brand_partner->priority + 1;
            }

            $brand_partner->save();
            $nextPartner->save();

            return redirect()->back()->with('success', 'Priority moved down.');
        }

        return redirect()->back()->with('info', 'Brand partner is already at the bottom.');
    }

    public function sendManualNotification(BrandPartner $brand_partner): RedirectResponse
    {
        if (!$brand_partner->is_active) {
            return redirect()->back()->with('error', 'Cannot send notifications for inactive brand partners.');
        }

        $type = !empty($brand_partner->offer_title) ? 'offer' : 'joined';
        SendBulkPartnerNotificationJob::dispatch($brand_partner, $type);

        return redirect()->back()->with('success', 'Manual notification broadcast triggered successfully.');
    }
}
