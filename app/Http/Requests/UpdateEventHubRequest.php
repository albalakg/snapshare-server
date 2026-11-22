<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'hub_config.template_id'       => 'nullable|string',
            'hub_config.theme'             => 'nullable|array',
            'hub_config.blocks'            => 'required|array',
            'hub_config.blocks.*.id'       => 'required|string|max:64',
            'hub_config.blocks.*.type'     => 'required|string|max:64',
            'hub_config.blocks.*.enabled'  => 'required|boolean',
            'hub_config.blocks.*.order'    => 'required|integer|min:1',
            // present|array: allow empty data {} (Laravel "required" rejects empty arrays)
            'hub_config.blocks.*.data'     => 'present|array',
            // Gen-2 layout variant (hero / gallery / schedule). No string rule on purpose: an unknown
            // value is normalized to null by HubVariantNormalizer instead of failing with a 422.
            'hub_config.blocks.*.variant'  => 'nullable',
        ];
    }
}
