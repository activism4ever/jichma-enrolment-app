<?php

namespace App\Http\Requests\Concerns;

use App\Models\Facility;
use Illuminate\Validation\Rule;

/**
 * Validation shared by creating and editing a facility.
 *
 * The dependent dropdowns narrow the choices in the browser, but the browser is
 * never the enforcement point: the ward is re-checked against the selected LGA
 * so a crafted request cannot file a facility under a ward from another LGA.
 *
 * Uniqueness is per ward, matching the database's unique(ward_id, name) index.
 * Facility names are deliberately not globally unique: "Primary Health Centre"
 * legitimately exists in many wards, and the existing register already holds
 * such names.
 */
trait FacilityRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function facilityRules(?Facility $ignore = null): array
    {
        $unique = Rule::unique('facilities', 'name')
            ->where(fn ($query) => $query->where('ward_id', (int) $this->input('ward_id')));

        if ($ignore !== null) {
            // On edit, the row's own name must not collide with itself.
            $unique->ignore($ignore->id);
        }

        return [
            'lga_id' => ['required', 'integer', Rule::exists('lgas', 'id')],
            'ward_id' => [
                'required',
                'integer',
                Rule::exists('wards', 'id')
                    ->where(fn ($query) => $query->where('lga_id', (int) $this->input('lga_id'))),
            ],
            'name' => ['required', 'string', 'max:200', $unique],
        ];
    }

    /**
     * Trim surrounding whitespace before validating, so a name that differs
     * only by a stray space is not accepted as a second facility.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function facilityMessages(): array
    {
        return [
            'lga_id.required' => 'Choose the LGA.',
            'lga_id.exists' => 'That LGA no longer exists.',
            'ward_id.required' => 'Choose the ward.',
            'ward_id.exists' => 'That ward does not belong to the selected LGA.',
            'name.required' => 'Enter the facility name.',
            'name.max' => 'The facility name may not be longer than 200 characters.',
            'name.unique' => 'A facility with this name already exists in that ward.',
        ];
    }
}
