<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HideEventAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assets' => 'required|array|between:1,2000',
            'assets.*' => 'integer|min:1'
        ];
    }
}
