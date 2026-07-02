<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IcebreakerInteractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_profile_id' => 'required|integer|min:1',
            'action'            => ['required', 'string', Rule::in(['LIKE', 'PASS'])],
        ];
    }
}
