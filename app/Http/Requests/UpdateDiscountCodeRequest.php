<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percent' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|integer|in:0,1',
        ];
    }
}
