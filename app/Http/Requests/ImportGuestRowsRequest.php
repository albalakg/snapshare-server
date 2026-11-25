<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rows already parsed and column-mapped in the browser (xlsx / csv).
 */
class ImportGuestRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'on_duplicate'      => 'sometimes|in:skip,update',
            'rows'              => 'required|array|min:1|max:5000',
            'rows.*'            => 'array',
            'rows.*._line'      => 'sometimes|integer|min:1',
            'rows.*.full_name'  => 'nullable|string|max:300',
            'rows.*.first_name' => 'nullable|string|max:150',
            'rows.*.last_name'  => 'nullable|string|max:150',
            'rows.*.phone'      => 'nullable|string|max:64',
            'rows.*.email'      => 'nullable|string|max:300',
            'rows.*.party_size' => 'nullable|string|max:20',
            'rows.*.group'      => 'nullable|string|max:200',
            'rows.*.side'       => 'nullable|string|max:60',
            'rows.*.status'     => 'nullable|string|max:60',
            'rows.*.notes'      => 'nullable|string|max:2000',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Spreadsheet cells can arrive as numbers; coerce scalars to strings.
        $rows = $this->input('rows');
        if (is_array($rows)) {
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $k => $v) {
                    if ($k !== '_line' && (is_int($v) || is_float($v))) {
                        $rows[$i][$k] = (string) $v;
                    }
                }
            }
            $this->merge(['rows' => $rows]);
        }
    }
}
