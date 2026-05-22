<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

class StoreP2pMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'peer_user_id' => ['required', 'uuid', 'exists:users,id'],
            'meeting_date' => ['required', 'date_format:Y-m-d'],
            'meeting_place' => ['required', 'string', 'max:255'],
            'remarks' => ['required', 'string'],
            'media_file_ids' => ['sometimes', 'array'],
            'media_file_ids.*' => ['uuid', 'exists:files,id'],
        ];
    }
}
