<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandPartnerSettingsController extends Controller
{
    private const SETTINGS_FILE = 'brand_partners_settings.json';

    public function index(): View
    {
        $settings = $this->loadSettings();

        return view('admin.brand-partners.settings', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cpc_rate' => ['nullable', 'numeric', 'min:0'],
            'cpm_rate' => ['nullable', 'numeric', 'min:0'],
            'enable_qr_redemption' => ['nullable', 'boolean'],
            'enable_geo_targeting' => ['nullable', 'boolean'],
            'enable_recommendations' => ['nullable', 'boolean'],
            'expiry_warning_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // Clean checkboxes
        $data['enable_qr_redemption'] = $request->boolean('enable_qr_redemption');
        $data['enable_geo_targeting'] = $request->boolean('enable_geo_targeting');
        $data['enable_recommendations'] = $request->boolean('enable_recommendations');

        Storage::disk('local')->put(self::SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT));

        return redirect()->route('admin.brand-partners.settings')->with('success', 'Settings updated successfully.');
    }

    private function loadSettings(): array
    {
        if (Storage::disk('local')->exists(self::SETTINGS_FILE)) {
            $content = Storage::disk('local')->get(self::SETTINGS_FILE);
            return json_decode($content, true) ?? $this->defaultSettings();
        }

        return $this->defaultSettings();
    }

    private function defaultSettings(): array
    {
        return [
            'cpc_rate' => 0.10,
            'cpm_rate' => 1.00,
            'enable_qr_redemption' => false,
            'enable_geo_targeting' => false,
            'enable_recommendations' => false,
            'expiry_warning_days' => 3,
        ];
    }
}
