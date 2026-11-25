<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveGuestCrmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'full_name'  => $required . '|string|min:1|max:120',
            'phone'      => 'sometimes|nullable|string|max:32',
            'email'      => 'sometimes|nullable|string|max:255',
            'status'     => 'sometimes|integer|min:0|max:4',
            'party_size' => 'sometimes|integer|min:1|max:30',
            'side'       => 'sometimes|nullable|in:bride,groom,shared',
            'group_id'   => 'sometimes|nullable|integer|min:0',
            'group_name' => 'sometimes|nullable|string|max:80',
            'notes'      => 'sometimes|nullable|string|max:1000',
        ];
    }
}
