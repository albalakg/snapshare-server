<?php

namespace App\Http\Requests;

use App\Services\Enums\HubBlockTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventHubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug'                         => 'nullable|string|max:100',
            'is_published'                 => 'required|boolean',
            'hub_config'                   => 'required|array',
            'hub_config.theme'             => 'nullable|array',
            'hub_config.blocks'            => 'required|array',
            'hub_config.blocks.*.id'       => 'required|string|max:64',
            'hub_config.blocks.*.type'     => ['required', 'string', Rule::in(HubBlockTypeEnum::validKeys())],
            'hub_config.blocks.*.enabled'  => 'required|boolean',
            'hub_config.blocks.*.order'    => 'required|integer|min:1',
            'hub_config.blocks.*.data'     => 'required|array',
        ];
    }
}
