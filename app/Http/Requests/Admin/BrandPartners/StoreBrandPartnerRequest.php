<?php

namespace App\Http\Requests\Admin\BrandPartners;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrandPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Rely on middleware/policy authorization
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:brand_partners,slug'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp,jpg', 'max:5120'],
            'cover_image' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp,jpg', 'max:10240'],
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
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
            'is_sponsored' => ['nullable', 'boolean'],
            // Metadata fields
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'terms_and_conditions' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'keywords' => ['nullable', 'string', 'max:255'],
        ];
    }
}
