<?php

namespace App\Http\Requests;

use App\Models\Lga;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a location list upload.
 *
 * The confirmation checkbox is required, not merely informative. An import
 * rewrites the list every enrolee form depends on, so it should be something an
 * administrator does deliberately rather than something they can do by opening
 * the page and pressing Import.
 *
 * The MIME check is not trusted on its own: browsers report CSV inconsistently,
 * so the controller confirms the content really is CSV before reading it.
 */
class ImportLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importLocations', Lga::class);
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:2048',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/octet-stream',
            ],
            'confirm_replace' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.max' => 'That file is too large. Import locations in batches under 2 MB.',
            'mimetypes' => 'That does not look like a CSV file.',
            'confirm_replace.accepted' => 'Confirm that you want to add or update the location list.',
        ];
    }

    public function attributes(): array
    {
        return ['confirm_replace' => 'confirmation'];
    }
}
