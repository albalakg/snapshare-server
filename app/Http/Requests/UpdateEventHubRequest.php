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
            // Save the Date presentation slice. Unknown style/presentation are
            // normalized in SaveTheDateNormalizer (no 422), same idea as variants.
            'hub_config.presentation'                  => 'nullable|string|max:32',
            'hub_config.save_the_date'                 => 'nullable|array',
            'hub_config.save_the_date.style'           => 'nullable|string|max:32',
            'hub_config.save_the_date.style_opts'      => 'nullable|array',
            'hub_config.save_the_date.content'         => 'nullable|array',
            'hub_config.save_the_date.content.kind'    => 'nullable|string|max:32',
            'hub_config.save_the_date.content.a'       => 'nullable|string|max:200',
            'hub_config.save_the_date.content.b'       => 'nullable|string|max:200',
            'hub_config.save_the_date.content.title'   => 'nullable|string|max:200',
            'hub_config.save_the_date.content.sub'     => 'nullable|string|max:200',
            'hub_config.save_the_date.content.date'    => 'nullable|string|max:32',
            'hub_config.save_the_date.content.time'    => 'nullable|string|max:16',
            'hub_config.save_the_date.content.venue'   => 'nullable|string|max:200',
            'hub_config.save_the_date.content.city'    => 'nullable|string|max:200',
            'hub_config.save_the_date.content.line'    => 'nullable|string|max:200',
            'hub_config.save_the_date.content.photo'   => 'nullable',
            'hub_config.save_the_date.show'            => 'nullable|array',
            'hub_config.save_the_date.show.*'          => 'nullable|boolean',
        ];
    }
}
