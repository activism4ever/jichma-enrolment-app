<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use RuntimeException;

/**
 * Replace the location list from an uploaded CSV.
 *
 * The file has the same three columns as the shipped seed data: lga, ward,
 * facility. Rows are upserted by name within their parent rather than
 * deleted and recreated, because enrolees point at lga_id/ward_id/facility_id:
 * removing a row would leave every past registration pointing at a facility that
 * no longer exists.
 *
 * The whole import runs in one transaction. A location list is only useful
 * complete, and a half-applied import would silently corrupt every dropdown in
 * the enrolee form.
 */
class LocationImporter
{
    private const COLUMNS = ['lga', 'ward', 'facility'];

    /**
     * @return array{lgas: int, wards: int, facilities: int, skipped: int}
     */
    public function import(string $absolutePath): array
    {
        $counts = ['lgas' => 0, 'wards' => 0, 'facilities' => 0, 'skipped' => 0];

        $options = new CsvOptions;
        // Trailing blank lines are normal in a spreadsheet export, and OpenSpout
        // drops them rather than yielding empty rows.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = false;

        $reader = new CsvReader($options);

        try {
            $reader->open($absolutePath);
        } catch (OpenSpoutException $e) {
            throw new RuntimeException('That file could not be read as CSV: '.$e->getMessage());
        }

        try {
            $sheet = $reader->getSheetIterator()->current();

            if ($sheet === null) {
                throw new RuntimeException('That file is empty.');
            }

            $rows = $sheet->getRowIterator();

            // OpenSpout 4 has no header handling of its own: the first row is
            // data, and the column order is established here. rewind() must be
            // called explicitly because a bare current() returns nothing until
            // the iterator has been primed; it is harmless when the foreach
            // below rewinds again.
            $rows->rewind();

            $headerCells = $rows->current()?->toArray() ?? [];
            $map = $this->columnMap($headerCells);

            if ($map === null) {
                $found = array_values(array_filter(
                    array_map(fn ($value) => trim((string) $value), $headerCells),
                    fn ($value) => $value !== ''
                ));

                throw new RuntimeException(sprintf(
                    'The first row must name the columns: %s. Found: %s.',
                    implode(', ', self::COLUMNS),
                    $found === [] ? 'an empty first row' : implode(', ', $found)
                ));
            }

            DB::transaction(function () use ($rows, $map, &$counts) {
                // Caches keyed by parent name, so a CSV with 279 facilities and
                // 27 LGAs does not repeat the same lookup 279 times.
                $lgaIds = [];
                $wardIds = [];

                // Walked by hand rather than with foreach: the iterator has
                // already been primed to read the header, and a foreach would
                // rewind and hand the header back as a data row.
                $rows->next();

                while ($rows->valid()) {
                    $cells = $rows->current()?->toArray() ?? [];

                    $lgaName = $this->clean($cells[$map['lga']] ?? null);
                    $wardName = $this->clean($cells[$map['ward']] ?? null);
                    $facilityName = $this->clean($cells[$map['facility']] ?? null);

                    if ($lgaName !== null && $wardName !== null && $facilityName !== null) {
                        $lgaIds[$lgaName] ??= $this->lgaIdFor($lgaName);
                        $counts['lgas'] = count($lgaIds);

                        $wardKey = $lgaIds[$lgaName].':'.$wardName;
                        $wardIds[$wardKey] ??= $this->wardIdFor($lgaIds[$lgaName], $wardName);
                        $counts['wards'] = count($wardIds);

                        $this->facilityIdFor($wardIds[$wardKey], $facilityName);
                        $counts['facilities']++;
                    } else {
                        // Counted rather than thrown on: one incomplete row
                        // should not lose the other 278.
                        $counts['skipped']++;
                    }

                    $rows->next();
                }
            });
        } finally {
            $reader->close();
        }

        return $counts;
    }

    /** Import the file that ships with the application, for a first install. */
    public function importBundledCsv(): array
    {
        $path = database_path('data/locations_clean.csv');

        if (! is_file($path)) {
            throw new RuntimeException('The bundled location file is missing from this installation.');
        }

        return $this->import($path);
    }

    /**
     * Map each required column to its index, matching header names case- and
     * whitespace-insensitively so " LGA " still works.
     *
     * @param  array<int, mixed>  $headerCells
     * @return array<string, int>|null null when a required column is missing
     */
    private function columnMap(array $headerCells): ?array
    {
        $normalised = [];

        foreach ($headerCells as $index => $value) {
            $value = trim(mb_strtolower((string) $value));

            if ($value !== '' && ! isset($normalised[$value])) {
                $normalised[$value] = $index;
            }
        }

        $map = [];

        foreach (self::COLUMNS as $column) {
            if (! isset($normalised[$column])) {
                return null;
            }

            $map[$column] = $normalised[$column];
        }

        return $map;
    }

    private function lgaIdFor(string $name): int
    {
        return (int) Lga::query()->firstOrCreate(['name' => $name])->id;
    }

    private function wardIdFor(int $lgaId, string $name): int
    {
        return (int) Ward::query()->firstOrCreate(
            ['lga_id' => $lgaId, 'name' => $name]
        )->id;
    }

    private function facilityIdFor(int $wardId, string $name): int
    {
        return (int) Facility::query()->firstOrCreate(
            ['ward_id' => $wardId, 'name' => $name]
        )->id;
    }

    /** Trim, and reject a name that is only whitespace. */
    private function clean($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_substr(trim((string) $value), 0, 191);

        return $value === '' ? null : $value;
    }
}
