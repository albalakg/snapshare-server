<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'bail|required|string|min:2|max:32|regex:/^[A-Za-z0-9_-]+$/',
            'percent' => 'bail|required|integer|min:1|max:100',
            'status' => 'bail|required|integer|in:0,1',
        ];
    }
}
