<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EnroleeRules;
use App\Models\Enrolee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a submission: all required fields plus the photograph.
 *
 * Also handles the "save straight to submitted" path used by the form's Submit
 * button, so a Program User can register and submit in one interaction.
 */
class SubmitEnroleeRequest extends FormRequest
{
    use EnroleeRules;

    /**
     * The stored fields a submission must carry. Merged in as defaults so the
     * record page and the edit form validate identically.
     *
     * @var list<string>
     */
    private const FIELD_KEYS = [
        'surname', 'first_name', 'middle_name', 'gender', 'date_of_birth',
        'marital_status', 'education', 'phone', 'nin', 'address',
        'lga_id', 'ward_id', 'facility_id',
    ];

    public function authorize(): bool
    {
        $enrolee = $this->route('enrolee');

        return $enrolee
            ? $this->user()->can('submit', $enrolee)
            : $this->user()->can('create', \App\Models\Enrolee::class);
    }

    public function rules(): array
    {
        $rules = $this->submitRules();

        // Ignore the NIN held by the record being submitted, otherwise editing a
        // draft and submitting it would collide with itself.
        if ($this->route('enrolee')) {
            $rules['nin'] = array_merge(
                ['required', 'string', $this->ninIsElevenDigits()],
                [$this->ninRulesIgnoring($this->route('enrolee')->id)]
            );
        }

        return $rules;
    }

    public function messages(): array
    {
        return $this->baseMessages();
    }

    /**
     * Supply a virtual "the record already has a photograph" flag so that
     * required_without passes when the photo was uploaded on an earlier save.
     */
    protected function prepareForValidation(): void
    {
        $enrolee = $this->route('enrolee');

        if (! $enrolee) {
            return;
        }

        // Submitting is reachable from the record page as well as from the edit
        // form, and from the record page the browser posts only the photograph.
        // The stored values are merged in as defaults so the same full
        // validation runs either way: a half-filled draft still cannot submit,
        // it simply reports which fields are missing.
        foreach (self::FIELD_KEYS as $key) {
            if ($this->exists($key)) {
                continue;
            }

            $this->merge([$key => $this->storedValue($enrolee, $key)]);
        }

        if (filled($enrolee->photo_path) && ! $this->hasFile('photo')) {
            $this->merge(['has_existing_photo' => '1']);
        }
    }

    /**
     * The record's current value for a field, in the shape the validator and
     * the database both expect.
     */
    private function storedValue(Enrolee $enrolee, string $key): mixed
    {
        if ($key === 'date_of_birth') {
            return $enrolee->date_of_birth?->format('Y-m-d');
        }

        $value = $enrolee->{$key};

        // The enum-backed fields must go back into the request as their string
        // values, not as enum instances.
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}