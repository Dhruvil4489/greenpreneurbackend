<?php

namespace App\Http\Controllers\Api\V1\Forms;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Forms\StoreVisitorRegistrationRequest;
use App\Models\VisitorRegistration;
use Illuminate\Http\Request;

class VisitorRegistrationController extends BaseApiController
{
    public function store(StoreVisitorRegistrationRequest $request)
    {
        $authUser = $request->user();
        $data = $request->validated();

        $invitedByType = $data['invited_by_type'] ?? null;
        $invitedByUserId = in_array($invitedByType, ['circle_member_peer', 'other'], true)
            ? ($data['invited_by_user_id'] ?? null)
            : null;
        $visitorBusinessCategoryId = array_key_exists('visitor_business_category_id', $data) && $data['visitor_business_category_id'] !== null && $data['visitor_business_category_id'] !== ''
            ? (int) $data['visitor_business_category_id']
            : null;

        $registration = VisitorRegistration::create([
            'user_id' => $authUser->id,
            'event_type' => $data['event_type'],
            'event_name' => $data['event_name'],
            'event_date' => $data['event_date'],
            'visitor_full_name' => $data['visitor_full_name'],
            'visitor_mobile' => $data['visitor_mobile'],
            'visitor_email' => $data['visitor_email'] ?? null,
            'visitor_city' => $data['visitor_city'],
            'visitor_business' => $data['visitor_business'],
            'visitor_designation' => $data['visitor_designation'] ?? null,
            'visitor_business_category_id' => $visitorBusinessCategoryId,
            'visitor_business_category' => $data['visitor_business_category'] ?? null,
            'visitor_business_website' => $data['visitor_business_website'] ?? null,
            'visitor_business_brief' => $data['visitor_business_brief'] ?? null,
            'invited_by_type' => $invitedByType,
            'invited_by_user_id' => $invitedByUserId,
            'how_known' => $data['how_known'] ?? $invitedByType,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
            'coins_awarded' => false,
        ]);

        $updatedLifeImpact = $this->increaseLifeImpact(
            (string) $authUser->id,
            1,
            'visitor_registration',
            'Brought a quality visitor to the meeting',
            (string) $authUser->id,
            null,
            'Life impact added for visitor registration activity.',
            [
                'event_type' => $registration->event_type,
                'event_name' => $registration->event_name,
                'event_date' => $registration->event_date,
                'visitor_full_name' => $registration->visitor_full_name,
                'visitor_mobile' => $registration->visitor_mobile,
                'visitor_email' => $registration->visitor_email,
                'visitor_city' => $registration->visitor_city,
                'visitor_business' => $registration->visitor_business,
                'how_known' => $registration->how_known,
                'visitor_designation' => $registration->visitor_designation,
                'visitor_business_category_id' => $registration->visitor_business_category_id,
                'visitor_business_category' => $registration->visitor_business_category,
                'visitor_business_website' => $registration->visitor_business_website,
                'visitor_business_brief' => $registration->visitor_business_brief,
                'invited_by_type' => $registration->invited_by_type,
                'invited_by_user_id' => $registration->invited_by_user_id,
                'note' => $registration->note,
            ]
        );

        return $this->success([
            'id' => $registration->id,
            'status' => $registration->status,
            'created_at' => $registration->created_at,
            'life_impacted_count' => $updatedLifeImpact,
        ], 'Visitor registration submitted successfully.', 201);
    }

    public function myIndex(Request $request)
    {
        $authUser = $request->user();

        $items = VisitorRegistration::query()
            ->where('user_id', $authUser->id)
            ->orderByDesc('created_at')
            ->select([
                'id',
                'event_type',
                'event_name',
                'event_date',
                'visitor_full_name',
                'visitor_mobile',
                'visitor_city',
                'visitor_business',
                'visitor_designation',
                'visitor_business_category_id',
                'visitor_business_category',
                'visitor_business_website',
                'visitor_business_brief',
                'invited_by_type',
                'invited_by_user_id',
                'status',
                'created_at',
            ])
            ->get();

        return $this->success([
            'items' => $items,
        ]);
    }
}
