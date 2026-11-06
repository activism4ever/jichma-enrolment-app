<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FacilityRules;
use App\Models\Lga;
use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityRequest extends FormRequest
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
        return $this->facilityRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->facilityMessages();
    }
}
