<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkGuestCrmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_ids'        => 'required|array|min:1|max:5000',
            'guest_ids.*'      => 'integer|min:1',
            'changes'          => 'sometimes|array',
            'changes.status'   => 'sometimes|integer|min:0|max:4',
            'changes.side'     => 'sometimes|nullable|in:bride,groom,shared',
            'changes.group_id' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
