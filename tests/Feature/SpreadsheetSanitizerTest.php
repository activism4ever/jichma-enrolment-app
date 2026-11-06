<?php

use App\Jobs\GenerateExport;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Program;
use App\Services\EnroleeExporter;
use App\Services\ExportService;
use App\Services\PhotoService;
use App\Services\SpreadsheetSanitizer;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Spreadsheet formula-injection defence
|--------------------------------------------------------------------------
|
| A cell that begins with =, +, - or @ is executed as a formula by Excel and
| LibreOffice, and a leading control character makes a naive check miss it.
| The export pipeline prefixes such cells with a single quote, which Excel
| treats as "text follows". These tests pin both the neutralisation and the
| guarantee that ordinary values survive unchanged.
|
*/

function dangerousCells(): array
{
    return [
        'equals' => '=HYPERLINK("http://evil.example","Click me")',
        'plus' => '+1-555-evil',
        'minus' => '-cmd|\'/C calc.exe\'!A0',
        'at' => '@SUM(1+1)',
    ];
}

test('a leading formula prefix is neutralised with a text quote', function (string $input) {
    expect(SpreadsheetSanitizer::cell($input))->toBe("'".$input);
})->with(dangerousCells());

test('a control character in front of a prefix cannot sneak past', function () {
    // Excel treats a tab/newline as the true first character, so the prefix
    // check must run after those are stripped rather than before.
    expect(SpreadsheetSanitizer::cell("\t=SUM(A1)"))->toBe("'=SUM(A1)")
        ->and(SpreadsheetSanitizer::cell("\r=SUM(A1)"))->toBe("'=SUM(A1)")
        ->and(SpreadsheetSanitizer::cell("\n=SUM(A1)"))->toBe("'=SUM(A1)")
        ->and(SpreadsheetSanitizer::cell("\0=SUM(A1)"))->toBe("'=SUM(A1)")
        // A leading space is ordinary text, not a bypass: Excel does not treat
        // a cell that starts with a space as a formula, so a name such as
        // " - Anne" must survive untouched rather than be prefixed or trimmed.
        ->and(SpreadsheetSanitizer::cell(' -2+3'))->toBe(' -2+3')
        // Control characters are stripped only from the front; one sitting in
        // the middle of a legitimate value is data and stays.
        ->and(SpreadsheetSanitizer::cell("A\tB"))->toBe("A\tB");
});

test('ordinary values pass through unchanged', function () {
    expect(SpreadsheetSanitizer::cell('Ada Lovelace'))->toBe('Ada Lovelace')
        ->and(SpreadsheetSanitizer::cell('12345678901'))->toBe('12345678901')
        ->and(SpreadsheetSanitizer::cell('08031234567'))->toBe('08031234567')
        ->and(SpreadsheetSanitizer::cell('Amina Yusuf'))->toBe('Amina Yusuf')
        ->and(SpreadsheetSanitizer::cell('a=b, at@hand'))->toBe('a=b, at@hand')
        ->and(SpreadsheetSanitizer::cell(null))->toBe('')
        ->and(SpreadsheetSanitizer::cell(true))->toBe('Yes')
        ->and(SpreadsheetSanitizer::cell(false))->toBe('')
        ->and(SpreadsheetSanitizer::cell(''))->toBe('')
        ->and(SpreadsheetSanitizer::cell(now()))->toBe(now()->format('Y-m-d H:i:s'));
});

test('the exporter pipeline runs every enrolee cell through the sanitizer', function () {
    // Column names are words; sanitising them must be a no-op.
    expect(EnroleeExporter::headerRow())->toBe(EnroleeExporter::HEADERS);

    $program = Program::factory()->create(['code' => 'CEMOG']);

    $enrolee = Enrolee::factory()->forProgram($program)->create([
        'surname' => '=HYPERLINK("http://evil.example","Click me")',
        'address' => '+1-555-evil',
        'nin' => '12345678901',
        'phone' => '08031234567',
    ]);

    $row = EnroleeExporter::row($enrolee);
    $cells = array_combine(EnroleeExporter::HEADERS, $row);

    // Dangerous free-text fields are quoted back at the workbook...
    expect($cells['surname'])->toBe("'=HYPERLINK(\"http://evil.example\",\"Click me\")")
        ->and($cells['address'])->toBe("'+1-555-evil");

    // ...while identifiers that never begin with a formula operator are left
    // as the exact numbers the operator typed.
    expect($cells['nin'])->toBe('12345678901')
        ->and($cells['phone'])->toBe('08031234567')
        ->and($cells['program'])->toBe('CEMOG');
});

test('a generated CSV file carries the defended cells, not the raw ones', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG']);

    Enrolee::factory()->forProgram($program)->create([
        'surname' => '=HYPERLINK("http://evil.example","Click me")',
        'address' => "+1-555-evil",
        'nin' => '12345678901',
        'phone' => '08031234567',
    ]);

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'pending',
        'disk' => 'private',
        'path' => 'exports/formula-defence.csv',
        'expires_at' => now()->addDay(),
    ]);

    (new GenerateExport($export->id, ['program_id' => $program->id]))
        ->handle(app(ExportService::class), app(PhotoService::class));

    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(1);

    // A BOM is written so Excel opens the UTF-8 names correctly; take it off
    // before parsing the cells.
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', Storage::disk('private')->get($export->path));

    $rows = collect(explode("\n", rtrim((string) $csv)))
        ->filter(fn ($line) => trim($line) !== '')
        ->map(fn ($line) => str_getcsv($line, ',', '"', ''))
        ->values();

    $header = $rows->first();
    $cell = fn (string $column) => $rows->get(1)[array_search($column, $header, true)] ?? null;

    // The pipe survives all the way into the file Excel downloads.
    expect($cell('surname'))->toBe("'=HYPERLINK(\"http://evil.example\",\"Click me\")")
        ->and($cell('address'))->toBe("'+1-555-evil")
        ->and($cell('nin'))->toBe('12345678901')
        ->and($cell('phone'))->toBe('08031234567');
});