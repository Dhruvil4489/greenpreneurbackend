<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandPartnerCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrandPartnerCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $categories = BrandPartnerCategory::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where('name', 'ILIKE', '%' . $search . '%');
            })
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.brand-partners.categories', compact('categories', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        if (empty($data['sort_order'])) {
            $data['sort_order'] = BrandPartnerCategory::count() + 1;
        }

        BrandPartnerCategory::query()->create($data);

        return redirect()->route('admin.brand-partners.categories.index')->with('success', 'Category created successfully.');
    }

    public function update(Request $request, BrandPartnerCategory $brand_partner_category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $brand_partner_category->update($data);

        return redirect()->route('admin.brand-partners.categories.index')->with('success', 'Category updated successfully.');
    }

    public function destroy(BrandPartnerCategory $brand_partner_category): RedirectResponse
    {
        $brand_partner_category->delete();

        return redirect()->route('admin.brand-partners.categories.index')->with('success', 'Category deleted successfully.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'uuid', 'exists:brand_partner_categories,id'],
        ]);

        foreach ($request->input('order') as $index => $id) {
            BrandPartnerCategory::where('id', $id)->update(['sort_order' => $index]);
        }

        return redirect()->back()->with('success', 'Categories reordered.');
    }
}
