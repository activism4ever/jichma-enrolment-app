<?php

namespace App\Services;

use App\Models\Enrolee;

/**
 * Flattens an enrolee into the export column set.
 *
 * Readable names are resolved for LGA, ward, facility and program so that a
 * spreadsheet is usable without a second lookup. Numeric identifiers are kept
 * in their own columns for reconciliation.
 */
final class EnroleeExporter
{
    public const HEADERS = [
        'id',
        'program',
        'program_id',
        'status',
        'surname',
        'first_name',
        'middle_name',
        'full_name',
        'gender',
        'date_of_birth',
        'age',
        'marital_status',
        'education',
        'phone',
        'nin',
        'address',
        'lga',
        'lga_id',
        'ward',
        'ward_id',
        'facility',
        'facility_id',
        'registered_by',
        'registered_by_email',
        'created_at',
        'submitted_at',
        'photo_file',
        'deleted_at',
    ];

    /**
     * Column order and human-readable labels for the header row.
     */
    public static function headerRow(): array
    {
        return SpreadsheetSanitizer::row(self::HEADERS);
    }

    /**
     * Values for one enrolee, in HEADERS order.
     *
     * @return list<string>
     */
    public static function row(Enrolee $enrolee): array
    {
        return SpreadsheetSanitizer::row([
            $enrolee->id,
            $enrolee->program?->code,
            $enrolee->program_id,
            $enrolee->status->value,
            $enrolee->surname,
            $enrolee->first_name,
            $enrolee->middle_name,
            $enrolee->full_name,
            $enrolee->gender?->value,
            $enrolee->date_of_birth?->format('Y-m-d'),
            $enrolee->ageInYears(),
            $enrolee->marital_status?->value,
            $enrolee->education?->value,
            $enrolee->phone,
            $enrolee->nin,
            $enrolee->address,
            $enrolee->lga?->name,
            $enrolee->lga_id,
            $enrolee->ward?->name,
            $enrolee->ward_id,
            $enrolee->facility?->name,
            $enrolee->facility_id,
            $enrolee->creator?->name,
            $enrolee->creator?->email,
            $enrolee->created_at?->format('Y-m-d H:i:s'),
            $enrolee->submitted_at?->format('Y-m-d H:i:s'),
            $enrolee->photoFilename(),
            $enrolee->deleted_at?->format('Y-m-d H:i:s'),
        ]);
    }
}