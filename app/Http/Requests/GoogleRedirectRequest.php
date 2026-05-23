<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoogleRedirectRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'redirect' => 'required|url',
            'post_login_redirect' => 'nullable|string|max:2048',
        ];
    }
}
