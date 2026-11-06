<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EnroleeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate saving or updating a draft.
 *
 * Partial data is allowed. Anything that is present must still be correct: a
 * NIN typed into a draft must be 11 digits and unique, and a phone number must
 * normalise. That is what lets a half-finished form save while still keeping
 * the duplicate-NIN block intact.
 */
class StoreEnroleeRequest extends FormRequest
{
    use EnroleeRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Enrolee::class);
    }

    public function rules(): array
    {
        $rules = $this->draftRules();

        // On edit, the NIN already on the record must not collide with itself.
        if ($this->route('enrolee')) {
            $rules['nin'] = array_merge(
                ['nullable', 'string', $this->ninIsElevenDigits()],
                [$this->ninRulesIgnoring($this->route('enrolee')->id)]
            );
        }

        return $rules;
    }

    public function messages(): array
    {
        return $this->baseMessages();
    }
}