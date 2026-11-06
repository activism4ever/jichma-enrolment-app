<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate creating a programme.
 *
 * The code is the programme's permanent identity: it is unique, letters, digits,
 * hyphens and underscores only, and normalised to upper case here so the stored
 * value matches the shipped CEMOG / VESTILAR / PLHIV spellings. It is never
 * editable after this (see UpdateProgramRequest).
 *
 * `is_sensitive` must always arrive, because it decides whether a programme's
 * enrolee data gets extra masking; the form pairs the checkbox with a hidden
 * false so an unchecked box cannot be mistaken for a missing field.
 */
class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Program::class);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('programs', 'code'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_sensitive' => ['required', 'boolean'],
        ];
    }

    /**
     * Uppercase and trim the code before validation, so "cemog" and "CEMOG"
     * cannot become two programmes (the uniqueness check sees the normal form).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter a programme code.',
            'code.unique' => 'A programme with this code already exists.',
            'code.regex' => 'The code may contain only letters, numbers, hyphens and underscores.',
            'name.required' => 'Enter the programme name.',
            'is_sensitive.required' => 'Choose whether this programme holds sensitive data.',
        ];
    }

    public function attributes(): array
    {
        return ['is_sensitive' => 'sensitive data setting'];
    }
}
