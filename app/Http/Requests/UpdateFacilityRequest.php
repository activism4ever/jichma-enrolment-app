<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FacilityRules;
use App\Models\Facility;
use App\Models\Lga;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename or move an existing facility.
 *
 * The move itself is refused in the controller when registrations already point
 * at the facility. That check needs a database read, so it does not belong in a
 * validation rule.
 */
class UpdateFacilityRequest extends FormRequest
{
    use FacilityRules;

    public function authorize(): bool
    {
        return $this->user()->can('manageFacilities', Lga::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Facility $facility */
        $facility = $this->route('facility');

        return $this->facilityRules($facility);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->facilityMessages();
    }
}
