<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSeatingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision'               => 'required|integer|min:0',
            'tables'                 => 'present|array|max:200',
            'tables.*.id'            => 'nullable|integer|min:1',
            'tables.*.key'           => 'required|string|max:40',
            'tables.*.label'         => 'nullable|string|max:60',
            'tables.*.shape'         => 'required|in:round,rect',
            'tables.*.seats'         => 'required|integer|min:1|max:60',
            'tables.*.side'          => 'nullable|in:bride,groom,shared',
            'tables.*.x'             => 'required|numeric',
            'tables.*.y'             => 'required|numeric',
            'tables.*.w'             => 'required|numeric',
            'tables.*.h'             => 'required|numeric',
            'tables.*.rot'           => 'nullable|numeric',
            'fixtures'               => 'present|array|max:60',
            'fixtures.*.id'          => 'required|string|max:40',
            'fixtures.*.kind'        => 'required|string|max:16',
            'fixtures.*.label'       => 'nullable|string|max:40',
            'fixtures.*.x'           => 'required|numeric',
            'fixtures.*.y'           => 'required|numeric',
            'fixtures.*.w'           => 'required|numeric',
            'fixtures.*.h'           => 'required|numeric',
            'fixtures.*.rot'         => 'nullable|numeric',
            'assignments'            => 'present|array|max:5000',
            'assignments.*.guest_id' => 'required|integer|min:1',
            'assignments.*.table_key' => 'required|string|max:40',
            'assignments.*.seat'     => 'nullable|integer|min:0|max:60',
        ];
    }
}
