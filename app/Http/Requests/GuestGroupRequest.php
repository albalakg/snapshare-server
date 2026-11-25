<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'       => $required . '|string|min:1|max:80',
            'side'       => 'sometimes|nullable|in:bride,groom,shared',
            'color'      => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => 'sometimes|integer|min:0|max:1000',
        ];
    }
}
