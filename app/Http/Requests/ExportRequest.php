<?php

namespace App\Http\Requests;

use App\Enums\EnroleeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate an export request.
 *
 * There is deliberately no password field. Asking an administrator to re-enter
 * their password to queue a report they legitimately request many times a day
 * added friction without adding a control: the route is admin-only, the filters
 * are re-applied against the requester's own visibility when the job runs, and
 * every request and every download is written to the audit trail with the
 * administrator's identity on it. What that check actually bought was a second
 * place for a password to be typed, and a habit of typing it into pages that
 * had no need of it.
 *
 * Only the shape of the filters is checked here. What they resolve to is left
 * to EnroleeFilters, which ignores anything unrecognised, so this request
 * deliberately does not reject an odd but harmless value: an unknown status is
 * dropped rather than turned into an error, matching how the list screen
 * behaves.
 *
 * The date range does get strict validation, though, because from > to would
 * silently produce an empty file and look like a bug to the administrator.
 */
class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('export', \App\Models\Enrolee::class);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['csv', 'xlsx', 'photos'])],

            // Optional filters; presence is enough, values are normalised later.
            // A new export may only select an active programme; a deactivated
            // programme is not offered (its historical exports remain readable).
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('is_active', true)],
            'status' => ['nullable', Rule::in(EnroleeStatus::values())],
            'lga_id' => ['nullable', 'integer', Rule::exists('lgas', 'id')],
            'ward_id' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'facility_id' => ['nullable', 'integer', Rule::exists('facilities', 'id')],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'created_by' => ['nullable', 'integer', Rule::exists('users', 'id')],

            // A photo bundle of a whole program would be large and slow;
            // requiring a filter keeps the archive to something a shared host
            // can actually produce.
            'include_photos' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Registered to cannot be earlier than Registered from.',
            'from.date_format' => 'Registered from must be a real date in YYYY-MM-DD format.',
            'to.date_format' => 'Registered to must be a real date in YYYY-MM-DD format.',
            'kind.in' => 'Choose CSV, XLSX or Photos (ZIP).',
            'program_id.exists' => 'That programme is not active.',
        ];
    }

    public function attributes(): array
    {
        return [
            // Without these, the reversed-range error arrives as "The to field
            // must be..." next to a box labelled "Registered to", which reads as
            // two different things.
            'from' => 'Registered from',
            'to' => 'Registered to',
            'program_id' => 'program',
            'lga_id' => 'LGA',
            'ward_id' => 'ward',
            'facility_id' => 'facility',
            'created_by' => 'user',
        ];
    }
}
