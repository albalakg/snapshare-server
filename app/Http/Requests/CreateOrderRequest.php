<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
           'subscription' => 'bail|required|string|max:7|in:בסיסי,פרימיום,נסיון',
        ];
    }
}
