<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate editing a programme.
 *
 * Deliberately has no `code` rule. The code is the programme's permanent
 * identity — it labels exports, seeds and the audit trail — so it is fixed at
 * creation and changing it would require a deliberate, administrator-only
 * migration rather than an ordinary edit. A crafted request that submits a
 * different code is simply ignored, because the field is not read here.
 *
 * Status is not here either: activation and deactivation are their own audited
 * actions, so an edit can never take a programme out of service as a side
 * effect of correcting its name.
 */
class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');

        return $program && $this->user()->can('update', $program);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_sensitive' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the programme name.',
            'is_sensitive.required' => 'Choose whether this programme holds sensitive data.',
        ];
    }

    public function attributes(): array
    {
        return ['is_sensitive' => 'sensitive data setting'];
    }
}
