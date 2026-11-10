<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Education;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Http\Requests\PhoneNormalizer;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Field rules for an enrolee, shared by the draft and submit requests.
 *
 * The brief distinguishes two levels of strictness:
 *
 *   Draft    - validate only what has actually been typed. A half-filled form
 *              must save, but whatever is present must still be well formed.
 *   Submit   - every field required except middle name, plus the photograph.
 *
 * Both levels share the same field shapes, so the rules live here once and each
 * request decides whether to require them.
 */
trait EnroleeRules
{
    /**
     * An administrator registers into whichever program they choose, since
     * Admin has program_id = NULL and sees all three. Every other role is bound
     * to exactly one program and may not choose, so the field is not even
     * validated for them.
     *
     * Only ever applied on creation. A record's program cannot be reassigned
     * by editing it: moving a submitted registration between programs would
     * rewrite what was attested, so the id is not accepted as input on update
     * and the record keeps the program it was registered into.
     *
     * @return array<string, mixed>
     */
    protected function programRules(): array
    {
        if (! $this->user()?->isAdmin()) {
            return [];
        }

        if ($this->route('enrolee')) {
            return [];
        }

        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('is_active', true)],
        ];
    }

    /**
     * Field rules for a partially filled form (draft save or draft edit).
     *
     * `nullable` is deliberate and distinct from `sometimes`: it means "may be
     * absent, but if present must satisfy the rest of the rule".
     *
     * @return array<string, mixed>
     */
    protected function draftRules(): array
    {
        return $this->programRules() + [
            'surname' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'in:'.implode(',', $this->genderValues())],
            'date_of_birth' => ['nullable', 'date', ...$this->dateOfBirthRules()],
            'marital_status' => ['nullable', 'in:'.implode(',', $this->maritalValues())],
            'education' => ['nullable', 'in:'.implode(',', $this->educationValues())],
            'phone' => ['nullable', 'string', 'max:20', $this->phoneRules()],
            'nin' => ['nullable', 'string', $this->ninIsElevenDigits(), $this->ninRules()],
            'address' => ['nullable', 'string', 'max:300'],
            'lga_id' => ['nullable', 'integer', $this->lgaExists()],
            'ward_id' => ['nullable', 'integer', $this->wardBelongsToLga()],
            'facility_id' => ['nullable', 'integer', $this->facilityBelongsToWard()],
            'photo' => $this->photoRules(nullable: true),
        ];
    }

    /**
     * Field rules for a submission: everything required except middle name.
     *
     * @return array<string, mixed>
     */
    protected function submitRules(): array
    {
        return $this->programRules() + [
            'surname' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            // The placeholder option submits as an empty string, so `required` refuses it
            // and `in:` would as well. Being explicit in a comment here because it
            // is easy to "simplify" the placeholder into a real sentinel value and
            // silently start storing unchosen answers.
            'gender' => ['required', 'in:'.implode(',', $this->genderValues())],
            'date_of_birth' => ['required', 'date', ...$this->dateOfBirthRules()],
            'marital_status' => ['required', 'in:'.implode(',', $this->maritalValues())],
            'education' => ['required', 'in:'.implode(',', $this->educationValues())],
            'phone' => ['required', 'string', 'max:20', $this->phoneRules()],
            'nin' => ['required', 'string', $this->ninIsElevenDigits(), $this->ninRules()],
            'address' => ['required', 'string', 'max:300'],
            'lga_id' => ['required', 'integer', $this->lgaExists()],
            'ward_id' => ['required', 'integer', $this->wardBelongsToLga()],
            'facility_id' => ['required', 'integer', $this->facilityBelongsToWard()],
            // Required to submit, but only when no photo is already stored:
            // a draft may already hold the photograph from an earlier save.
            'photo' => $this->photoRules(nullable: false),
        ];
    }

    /**
     * Upload rules for the photograph.
     *
     * The MIME check is a validation rule rather than only a PhotoService guard
     * so that a rejected file comes back as a field error attached to the
     * photograph field, which is where the user can act on it, instead of as a
     * 500. PhotoService still re-checks the real bytes; this is the first of two
     * gates, not the only one.
     *
     * @return array<int, mixed>
     */
    protected function photoRules(bool $nullable): array
    {
        $rules = [$nullable ? 'nullable' : 'required_without:has_existing_photo'];

        $rules[] = 'file';
        $rules[] = 'max:5120';
        $rules[] = $this->photoIsRealImage();

        return $rules;
    }

    /**
     * Sniff the uploaded file's true content type.
     *
     * The browser-supplied type and the filename extension are both
     * attacker-controlled, so neither is consulted here.
     */
    protected function photoIsRealImage(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! $value instanceof \Illuminate\Http\UploadedFile || ! $value->isValid()) {
                return;
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath());

            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                $fail('The photograph must be a JPG or PNG image.');
            }
        };
    }

    /* ------------------------------------------------------------------ */
    /* Individual rules                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * A NIN is exactly 11 digits and may exist only once in the whole system:
     * across every program and every status, drafts included.
     *
     * Soft-deleted rows are included on purpose. Laravel's unique rule queries
     * the table directly rather than through the Eloquent builder, so the
     * SoftDeletes global scope does not apply and a trashed row is still seen.
     * That matches the unique index, which likewise still covers the row, and
     * it is what stops a NIN being re-registered by deleting the first record.
     * (There is no withTrashed() on this rule; the only soft-delete modifiers
     * are withoutTrashed() and onlyTrashed().)
     */
    protected function ninRules(): Unique
    {
        return Rule::unique('enrolees', 'nin');
    }

/**
     * A NIN must be exactly eleven digits and nothing else.
     *
     * Laravel's `digits:11` rule accepts any numeric value of the right length,
     * including a decimal or a signed number such as "+1234567890" or
     * "1.2345678901e1", so the shape is pinned with a regex as well. The brief
     * asks for `^\d{11}$` literally, and that is exactly what runs: eleven digit
     * characters and no sign, no decimal point, no exponent, no spaces.
     *
     * Nothing is trimmed or repaired here. A NIN carrying letters, punctuation
     * or padding is refused rather than quietly fixed, because
     * "1234-5678-9011" is not the token the register issued and guessing at it
     * risks colliding with the wrong person's record. The form is the place
     * that helps: app.js strips everything that is not a digit as the value is
     * typed, so the browser never offers an unreachable token.
     *
     * Note that Laravel's TrimStrings middleware runs before this rule, so a
     * value that arrived with padding is already trimmed by the time it is
     * checked. The regex itself stays literal.
     *
     * Applied on draft as well as submit, so a malformed NIN never reaches the
     * table at all.
     */
    protected function ninIsElevenDigits(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! is_string($value)) {
                return;
            }

            if (! preg_match('/^\d{11}$/', $value)) {
                $fail('NIN must be exactly 11 digits.');
            }
        };
    }

    /**
     * The same rule, ignoring the NIN already held by the record being edited.
     */
    protected function ninRulesIgnoring(?int $id): Unique
    {
        $rule = Rule::unique('enrolees', 'nin');

        return $id === null ? $rule : $rule->ignore($id);
    }

    /**
     * Age must be plausible: not in the future and not absurdly old. Age zero
     * is valid, so a newborn can be registered on the day they are born.
     */
    protected function dateOfBirthRules(): array
    {
        return [
            'before_or_equal:today',
            'after:'.now()->subYears(120)->toDateString(),
        ];
    }

    /**
     * Accepts Nigerian formats and normalises to a canonical 08012345678 style
     * local number:
     *
     *   08012345678      -> 08012345678
     *   +2348012345678   -> 08012345678
     *   2348012345678    -> 08012345678
     *   0801 234 5678    -> 08012345678
     *   8012345678       -> 08012345678
     */
    protected function phoneRules(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (PhoneNormalizer::normalize($value) === null) {
                $fail('Enter a valid Nigerian phone number, for example 08012345678 or +2348012345678.');
            }
        };
    }

    protected function lgaExists(): Exists
    {
        return Rule::exists('lgas', 'id');
    }

    /**
     * A ward must belong to the submitted LGA. This is the server-side half of
     * the cascading dropdown: the browser hides the mismatch, this rejects it.
     */
    protected function wardBelongsToLga(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! $this->filled('ward_id')) {
                return;
            }

            $exists = Ward::query()
                ->whereKey($value)
                ->where('lga_id', $this->input('lga_id'))
                ->exists();

            if (! $exists) {
                $fail('The selected ward does not belong to the selected LGA.');
            }
        };
    }

    /** A facility must belong to the selected ward. */
    protected function facilityBelongsToWard(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! $this->filled('facility_id')) {
                return;
            }

            $exists = Facility::query()
                ->whereKey($value)
                ->where('ward_id', $this->input('ward_id'))
                ->exists();

            if (! $exists) {
                $fail('The selected health facility does not belong to the selected ward.');
            }
        };
    }

    /**
     * The value every dropdown's placeholder option submits as.
     *
     * An empty string, so `required` rejects it and the browser's own required
     * handling stops the form before it is even sent. Nothing here depends on
     * this being empty, though: a submission is checked for it explicitly, so a
     * future template that gives the placeholder a non-empty marker value still
     * cannot store "not chosen" as though it were a real answer.
     */
    public const PLACEHOLDER = '';

    protected function genderValues(): array
    {
        return array_column(Gender::options(), 'value');
    }

    protected function maritalValues(): array
    {
        return array_column(MaritalStatus::options(), 'value');
    }

    protected function educationValues(): array
    {
        return array_column(Education::options(), 'value');
    }

    /**
     * Messages tuned for the duplicate-NIN rule, which is a deliberate business
     * rule rather than a validation accident.
     */
    protected function baseMessages(): array
    {
        return [
            'nin.required' => 'A National Identification Number (NIN) is required.',
            'nin.unique' => 'This NIN is already registered.',
            // A dropdown left on its placeholder is reported as a missing choice
            // rather than an invalid one, because that is what it is.
            'gender.required' => 'Choose a gender.',
            'gender.in' => 'Choose a gender.',
            'marital_status.required' => 'Choose a marital status.',
            'marital_status.in' => 'Choose a marital status.',
            'education.required' => 'Choose the highest level of education.',
            'education.in' => 'Choose the highest level of education.',
            'lga_id.required' => 'Choose a Local Government Area.',
            'ward_id.required' => 'Choose a ward.',
            'facility_id.required' => 'Choose a health facility.',
            'surname.required' => 'Enter the surname.',
            'first_name.required' => 'Enter the first name.',
            'date_of_birth.required' => 'Enter the date of birth.',
            'address.required' => 'Enter the residential address.',
            'phone.required' => 'Enter a phone number.',
            'photo.required_without' => 'A passport photograph is required to submit.',
            'photo.max' => 'The photograph must be 5 MB or smaller.',
            'photo.file' => 'Upload a valid image file.',
            'program_id.required' => 'Choose the program this registration belongs to.',
            'program_id.exists' => 'That programme is not active. Choose another.',
        ];
    }
}