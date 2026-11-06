<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportLocationsRequest;
use App\Http\Requests\StoreFacilityRequest;
use App\Http\Requests\UpdateFacilityRequest;
use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Services\LocationImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Review and replace the location list, and manage individual facilities.
 *
 * Locations are referenced by existing enrolees, so the import upserts by name
 * instead of deleting and recreating. An administrator can therefore add a new
 * facility without breaking the registrations that point at its neighbours.
 *
 * Individual facilities are added, renamed and moved here too. A facility is
 * never deleted: historical registrations keep pointing at it. A facility that
 * is already referenced by a registration may be renamed but not moved, because
 * each registration also stores the ward and LGA it was recorded under.
 */
class LocationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Lga::class);

        $lgaId = $request->integer('lga_id') ?: null;

        // After a failed facility form the page is reloaded with the submitted
        // LGA, so the ward dropdown can be rebuilt server-side without a round
        // trip to the JSON endpoints.
        $formLgaId = (int) old('lga_id');

        $counts = [
            'lgas' => Lga::query()->count(),
            'wards' => Ward::query()->count(),
            'facilities' => Facility::query()->count(),
            'enrolees' => Enrolee::query()->count(),
        ];

        return view('admin.locations.index', [
            'counts' => $counts,
            'lgas' => Lga::query()
                // select() must come before withCount(). withCount() appends
                // its subqueries to the select list, and a later select('lgas.*')
                // replaces that list, silently discarding wards_count and
                // enrolees_count. That was why every LGA showed zero wards.
                ->select('lgas.*')
                ->withCount('wards')
                ->withCount('enrolees')
                // Facilities sit two levels down, so a withCount cannot reach
                // them; a subquery over the join does.
                ->selectSub(
                    Ward::query()
                        ->selectRaw('count(*)')
                        ->join('facilities', 'facilities.ward_id', '=', 'wards.id')
                        ->whereColumn('wards.lga_id', 'lgas.id'),
                    'facilities_count'
                )
                ->orderBy('name')
                ->get(),
            'formWards' => $formLgaId === 0
                ? collect()
                : Ward::query()
                    ->where('lga_id', $formLgaId)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            'selectedLga' => $lgaId === null
                ? null
                : Lga::query()->with(['wards.facilities'])->find($lgaId),
        ]);
    }

    /**
     * Add a facility to an existing ward.
     *
     * The import path can already add facilities, but it requires uploading a
     * whole CSV to add one row. This is the same operation for a single new
     * health facility, with the LGA/ward pair re-checked on the server rather
     * than trusted from the dependent dropdowns.
     */
    public function storeFacility(StoreFacilityRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $ward = Ward::query()->with('lga')->findOrFail($data['ward_id']);

        $facility = Facility::create([
            'ward_id' => $ward->id,
            'name' => $data['name'],
        ]);

        $this->audit->log(
            'facility_created',
            "Added facility {$facility->name} to {$ward->name} ({$ward->lga->name})",
            $facility,
            [],
            [
                'name' => $facility->name,
                'ward_id' => $ward->id,
                'ward' => $ward->name,
                'lga_id' => $ward->lga_id,
                'lga' => $ward->lga->name,
            ],
        );

        return redirect()
            ->route('admin.locations.index', ['lga_id' => $ward->lga_id])
            ->with('status', "Facility \"{$facility->name}\" added to {$ward->name}.");
    }

    public function editFacility(Facility $facility): View
    {
        $this->authorize('manageFacilities', Lga::class);

        $facility->load('ward.lga');

        $lgaId = (int) old('lga_id', $facility->ward->lga_id);

        return view('admin.locations.facilities.edit', [
            'facility' => $facility,
            'lgas' => Lga::query()->orderBy('name')->get(['id', 'name']),
            'wards' => Ward::query()
                ->where('lga_id', $lgaId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'selectedLgaId' => $lgaId,
            'selectedWardId' => (int) old('ward_id', $facility->ward_id),
            // Include soft-deleted registrations: their facility reference still
            // occupies the table and still has to stay consistent with the
            // ward/LGA recorded on the row.
            'usageCount' => $facility->enrolees()->withTrashed()->count(),
        ]);
    }

    public function updateFacility(UpdateFacilityRequest $request, Facility $facility): RedirectResponse
    {
        $data = $request->validated();

        $facility->load('ward.lga');

        $oldWardId = (int) $facility->ward_id;
        $newWard = Ward::query()->with('lga')->findOrFail($data['ward_id']);

        $isMove = (int) $newWard->id !== $oldWardId;

        // A registration stores lga_id, ward_id and facility_id together. Moving
        // the facility would leave every existing row claiming a ward the
        // facility is no longer in, so a referenced facility may be renamed but
        // not moved. Unused facilities can still be corrected freely.
        if ($isMove && $facility->enrolees()->withTrashed()->exists()) {
            return back()->withInput()->withErrors([
                'ward_id' => 'This facility is used by existing registrations, so it cannot be moved to another ward. It can still be renamed.',
            ]);
        }

        $old = [
            'name' => $facility->name,
            'ward_id' => $oldWardId,
            'ward' => $facility->ward->name,
            'lga_id' => $facility->ward->lga_id,
            'lga' => $facility->ward->lga->name,
        ];

        $facility->name = $data['name'];
        $facility->ward_id = $newWard->id;
        $facility->save();

        $new = [
            'name' => $facility->name,
            'ward_id' => $newWard->id,
            'ward' => $newWard->name,
            'lga_id' => $newWard->lga_id,
            'lga' => $newWard->lga->name,
        ];

        $this->audit->log(
            $isMove ? 'facility_moved' : 'facility_updated',
            $isMove
                ? "Moved facility {$facility->name} from {$old['ward']} to {$newWard->name} ({$newWard->lga->name})"
                : "Renamed facility {$old['name']} to {$facility->name}",
            $facility,
            $old,
            $new,
        );

        return redirect()
            ->route('admin.locations.index', ['lga_id' => $newWard->lga_id])
            ->with('status', $isMove
                ? "Facility \"{$facility->name}\" moved to {$newWard->name}."
                : "Facility \"{$facility->name}\" updated.");
    }

    public function import(ImportLocationsRequest $request, LocationImporter $importer): RedirectResponse
    {
        $file = $request->file('file');

        // Confirm it really is CSV. getMimeType() reports the actual content,
        // not just the extension, so a renamed executable cannot get through.
        $mime = $file->getMimeType();

        if (! in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
            return back()->withInput()->with(
                'error',
                "That file looks like {$mime}, not CSV. Save it as a plain CSV and try again."
            );
        }

        try {
            $counts = $importer->import($file->getRealPath());
        } catch (RuntimeException $e) {
            // A malformed file is the administrator's mistake, so it comes back
            // as a form error rather than a 500.
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                'The import could not be completed. Nothing was changed; check the log for details.'
            );
        }

        $this->audit->log(
            'locations_imported',
            sprintf(
                'Imported locations: %d LGAs, %d wards, %d facilities',
                $counts['lgas'],
                $counts['wards'],
                $counts['facilities']
            ),
            // No single subject: an import spans every location row, so the
            // counts are recorded as the payload instead.
            null,
            [],
            $counts,
        );

        $message = sprintf(
            'Import complete: %d LGAs, %d wards and %d facilities now in use.',
            $counts['lgas'],
            $counts['wards'],
            $counts['facilities']
        );

        if ($counts['skipped'] > 0) {
            $message .= sprintf(' %d incomplete rows were skipped.', $counts['skipped']);
        }

        return redirect()->route('admin.locations.index')->with('status', $message);
    }

    /**
     * Re-read the list shipped with the application. Useful after a partial
     * import, and for a new install that skipped the seeder.
     */
    public function restoreBundled(Request $request, LocationImporter $importer): RedirectResponse
    {
        $this->authorize('importLocations', Lga::class);

        try {
            $counts = $importer->importBundledCsv();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The bundled location file could not be imported.');
        }

        $this->audit->log(
            'locations_imported',
            'Restored the bundled location list',
            null,
            [],
            $counts,
        );

        return redirect()->route('admin.locations.index')->with('status', sprintf(
            'Restored the bundled list: %d LGAs, %d wards, %d facilities.',
            $counts['lgas'],
            $counts['wards'],
            $counts['facilities']
        ));
    }

    public function template(): StreamedResponse
    {
        $this->authorize('viewAny', Lga::class);

        // Generated rather than shipped as a static file, so it cannot drift out
        // of step with what the importer expects.
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['lga', 'ward', 'facility']);
            fputcsv($out, ['Auyo', 'Auyakayi', 'Auyakayi Basic Health Clinic']);
            fputcsv($out, ['Auyo', 'Auyo', 'Auyo Primary Health Centre']);
            fclose($out);
        }, 'locations-template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
