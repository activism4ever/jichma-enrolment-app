<?php

namespace App\Services;

use App\Models\Enrolee;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Streams CSV and XLSX exports to disk without loading records into memory.
 *
 * OpenSpout writes rows out as they are added, so peak memory stays flat
 * regardless of row count. That is the whole reason it is used over
 * PhpSpreadsheet, which builds the entire workbook in RAM before saving and
 * would exhaust a shared host's memory_limit on a large export.
 *
 * Read the source in chunks so that Eloquent's relation eager-loading does not
 * quietly load every enrolee in the table either.
 */
class ExportService
{
    public const CHUNK = 500;

    /**
     * The enrolee columns the spreadsheet actually reads.
     *
     * Selecting the whole row would hydrate every column of every record —
     * including the password-era columns nothing here displays — which is pure
     * memory and pure time at 5,000 rows. Anything EnroleeExporter::row() does
     * not touch must not be listed here; that class is the specification.
     */
    private const COLUMNS = [
        'id',
        'program_id',
        'status',
        'surname',
        'first_name',
        'middle_name',
        'gender',
        'date_of_birth',
        'marital_status',
        'education',
        'phone',
        'nin',
        'address',
        'lga_id',
        'ward_id',
        'facility_id',
        'created_by',
        'created_at',
        'submitted_at',
        'photo_path',
        'deleted_at',
    ];

    /**
     * Eager loads, each narrowed to the columns the export prints.
     *
     * An unconstrained "with" loads every column of every related record. The
     * keys are kept because Eloquent matches the relation on them.
     */
    private const RELATIONS = [
        'program:id,code',
        'creator:id,name,email',
        'lga:id,name',
        'ward:id,name',
        'facility:id,ward_id,name',
    ];

    /**
     * Write a CSV export.
     *
     * @param  callable(int, int): void|null  $progress  receives rows written so far and the total
     * @return array{path: string, rows: int}
     */
    public function toCsv(Builder $query, string $absolutePath, ?callable $progress = null): array
    {
        $options = new CsvOptions();
        $options->FIELD_DELIMITER = ',';
        $options->FIELD_ENCLOSURE = '"';
        // A BOM makes Excel open UTF-8 names such as "Muhammadu" correctly
        // instead of mangling accented Nigerian characters.
        $options->SHOULD_ADD_BOM = true;

        $writer = new CsvWriter($options);
        $writer->openToFile($absolutePath);

        $writer->addRow(Row::fromValues(EnroleeExporter::headerRow()));

        $rows = 0;
        $this->eachEnrolee($query, function (Enrolee $enrolee) use ($writer, &$rows) {
            $writer->addRow(Row::fromValues(EnroleeExporter::row($enrolee)));
            $rows++;
        }, $progress);

        $writer->close();

        return ['path' => $absolutePath, 'rows' => $rows];
    }

    /**
     * Write an XLSX export.
     *
     * @param  callable(int, int): void|null  $progress  receives rows written so far and the total
     * @return array{path: string, rows: int}
     */
    public function toXlsx(Builder $query, string $absolutePath, ?callable $progress = null): array
    {
        $options = new XlsxOptions();
        // Fixed-width for identifiers so numeric-looking NINs keep their
        // leading zeros and Excel does not reformat them as dates or numbers.
        $options->DEFAULT_COLUMN_WIDTH = 18;

        $writer = new XlsxWriter($options);
        $writer->openToFile($absolutePath);

        $headerStyle = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(EnroleeExporter::headerRow(), $headerStyle));

        $rows = 0;
        $this->eachEnrolee($query, function (Enrolee $enrolee) use ($writer, &$rows) {
            $writer->addRow(Row::fromValues(EnroleeExporter::row($enrolee)));
            $rows++;
        }, $progress);

        $writer->close();

        return ['path' => $absolutePath, 'rows' => $rows];
    }

    /**
     * Walk a query in chunks, eager-loading only the relations each row needs.
     *
     * The chunk size is the memory ceiling: relations are held for one page at
     * a time, never for the whole result set.
     *
     * @param  callable(Enrolee): void  $callback
     * @param  callable(int, int): void|null  $progress
     */
    public function eachEnrolee(Builder $query, callable $callback, ?callable $progress = null): void
    {
        // Counted before the first page so the progress figure has a total to be
        // a fraction of. Cheap, indexed, and it stops the job reporting "of ?".
        $total = $this->countFor($query);
        $processed = 0;

        if ($progress !== null) {
            $progress(0, $total);
        }

        $query->select(self::COLUMNS)
            ->with(self::RELATIONS)
            ->chunkById(self::CHUNK, function ($chunk) use ($callback, $progress, &$processed, $total) {
                foreach ($chunk as $enrolee) {
                    $callback($enrolee);
                    $processed++;
                }

                // Reported per page, not per row: a database write per row would
                // cost more than the export it is reporting on.
                if ($progress !== null) {
                    $progress($processed, $total);
                }
            }, column: 'id');
    }

    public function countFor(Builder $query): int
    {
        // Clone so the caller's query is not mutated by the counting call.
        return (clone $query)->count();
    }

    /**
     * Sensible filename: program and timestamp, no user-supplied text.
     */
    public function filename(string $kind, ?string $programCode = null): string
    {
        $parts = ['enrolees'];

        if (filled($programCode)) {
            $parts[] = strtolower($programCode);
        }

        $parts[] = $kind;
        $parts[] = now()->format('Ymd-His');

        return implode('-', array_map(
            fn ($p) => preg_replace('/[^A-Za-z0-9_-]/', '', $p),
            $parts
        ));
    }
}