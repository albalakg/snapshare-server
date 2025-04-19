<?php

namespace App\Http\Requests;

use App\Rules\PasswordRule;
use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'email' => 'bail|required|email|unique:users,email',
            'password' => ['required', new PasswordRule],
            'first_name' => 'required|string|between:1,100',
            'last_name' => 'required|string|between:1,100',
        ];
    }
}
