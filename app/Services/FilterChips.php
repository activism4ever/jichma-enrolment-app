<?php

namespace App\Services;

use App\Enums\EnroleeStatus;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Collection;

/**
 * Turns an EnroleeFilters object into the removable chips shown above a count.
 *
 * A count that silently changes when a filter is typed is the reason people
 * distrust a list screen: "3 enrolees" next to a form they thought was empty.
 * Each chip therefore states the filter in words, and carries the link that
 * removes exactly that filter and leaves every other one alone.
 *
 * Labels are resolved from the collections the controller has already loaded
 * for the form's own dropdowns, so showing the chips costs no extra queries in
 * the common case.
 */
final class FilterChips
{
    /**
     * @param  Collection<int, Program>  $programs
     * @param  Collection<int, Lga>  $lgas
     * @return list<array{key: string, label: string, remove_url: string}>
     */
    public static function for(
        EnroleeFilters $filters,
        Collection $programs,
        Collection $lgas,
        string $routeName = 'admin.exports.index',
    ): array {
        $chips = [];

        foreach (self::labels($filters, $programs, $lgas) as $key => $label) {
            $chips[] = [
                'key' => $key,
                'label' => $label,
                'remove_url' => route($routeName, $filters->without($key)->toArray()),
            ];
        }

        return $chips;
    }

    /**
     * One "Name: value" phrase per active filter, in the order the form shows
     * them. A filter whose row has since been deleted falls back to its id
     * rather than vanishing, because a chip that disappears on refresh is
     * worse than one that says "id 41".
     *
     * @param  Collection<int, Program>  $programs
     * @param  Collection<int, Lga>  $lgas
     * @return array<string, string>
     */
    private static function labels(EnroleeFilters $filters, Collection $programs, Collection $lgas): array
    {
        $labels = [];

        if ($filters->programId !== null) {
            $program = $programs->firstWhere('id', $filters->programId);

            $labels['program_id'] = 'Program: '.($program?->label() ?? "id {$filters->programId}");
        }

        if ($filters->status !== null) {
            $status = EnroleeStatus::tryFrom($filters->status);

            $labels['status'] = 'Status: '.($status?->label() ?? $filters->status);
        }

        if ($filters->lgaId !== null) {
            $lga = $lgas->firstWhere('id', $filters->lgaId);

            $labels['lga_id'] = 'LGA: '.($lga?->name ?? "id {$filters->lgaId}");
        }

        if ($filters->wardId !== null) {
            $labels['ward_id'] = 'Ward: '.(Ward::find($filters->wardId)?->name ?? "id {$filters->wardId}");
        }

        if ($filters->facilityId !== null) {
            $labels['facility_id'] = 'Facility: '.(Facility::find($filters->facilityId)?->name ?? "id {$filters->facilityId}");
        }

        if ($filters->createdBy !== null) {
            $labels['created_by'] = 'Created by: '.(User::find($filters->createdBy)?->name ?? "id {$filters->createdBy}");
        }

        if ($filters->search !== null) {
            $labels['search'] = 'Search: "'.$filters->search.'"';
        }

        if ($filters->from !== null) {
            $labels['from'] = 'Registered from: '.$filters->from;
        }

        if ($filters->to !== null) {
            $labels['to'] = 'Registered to: '.$filters->to;
        }

        return $labels;
    }
}