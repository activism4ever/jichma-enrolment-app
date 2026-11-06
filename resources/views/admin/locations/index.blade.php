@extends('layouts.app')

@section('title', 'Locations')

@section('content')
    <div class="page-header">
        <h1>Locations</h1>
        <p class="page-subtitle">
            {{ number_format($counts['lgas']) }} LGAs, {{ number_format($counts['wards']) }} wards and
            {{ number_format($counts['facilities']) }} facilities, used by
            {{ number_format($counts['enrolees']) }} registrations.
        </p>
    </div>

    <div class="alert alert-info">
        Importing adds and updates locations by name; it never deletes one. That is
        deliberate: every registration points at a facility, so removing a row would
        orphan the records that already use it. Use <strong>Add a facility</strong>
        below for a single new health facility, and the <strong>Edit</strong> link
        beside a facility to rename or move it.
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2 class="card-title">Import a location list</h2>

            <p class="text-muted">
                A CSV with three columns &mdash; <code>lga</code>, <code>ward</code>,
                <code>facility</code> &mdash; in that order. Rows are read in order, so
                group by LGA and then ward.
                <a href="{{ route('admin.locations.template') }}">Download a template</a>.
            </p>

            <form method="POST" action="{{ route('admin.locations.import') }}"
                  enctype="multipart/form-data">
                @csrf

                <div class="field {{ $errors->has('file') ? 'has-error' : '' }}">
                    <label for="file">CSV file</label>
                    <input id="file" type="file" name="file" accept=".csv,text/csv" required>
                    <p class="field-hint">Up to 2 MB. Rows already present are updated, not duplicated.</p>
                    @error('file') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('confirm_replace') ? 'has-error' : '' }}">
                    <div class="field-inline">
                        <input id="confirm_replace" type="checkbox" name="confirm_replace" value="1"
                               @checked(old('confirm_replace'))>
                        <label for="confirm_replace">I have checked the file opens correctly in a spreadsheet</label>
                    </div>
                    @error('confirm_replace') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <span data-submit-label>Import locations</span>
                    </button>
                </div>
            </form>

            <hr>

            <h2 class="card-title">Restore the bundled list</h2>
            <p class="text-muted">
                Re-imports the file that ships with the application. Useful after a
                partial import, or on an installation that skipped the seeder.
            </p>
            <form method="POST" action="{{ route('admin.locations.restore') }}"
                  data-confirm="Re-import the location list shipped with the application?">
                @csrf
                <button type="submit" class="btn btn-secondary">
                    <span data-submit-label>Restore bundled list</span>
                </button>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title">LGAs</h2>

            <form method="GET" action="{{ route('admin.locations.index') }}" class="field">
                <label for="lga_id">Show the wards of one LGA</label>
                <select id="lga_id" name="lga_id">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $lga)
                        <option value="{{ $lga->id }}" @selected($selectedLga?->is($lga))>
                            {{ $lga->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">Show</button>
                    @if ($selectedLga)
                        <a href="{{ route('admin.locations.index') }}" class="btn btn-link">Clear</a>
                    @endif
                </div>
            </form>

            @if ($selectedLga)
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Ward</th>
                                <th>Facilities</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($selectedLga->wards->sortBy('name') as $ward)
                                <tr>
                                    <td data-label="Ward">{{ $ward->name }}</td>
                                    <td data-label="Facilities">
                                        @if ($ward->facilities->isEmpty())
                                            <span class="text-muted">None yet</span>
                                        @else
                                            <ul class="plain-list">
                                                @foreach ($ward->facilities->sortBy('name') as $facility)
                                                    <li>
                                                        {{ $facility->name }}
                                                        @if ($facility->enrolees()->exists())
                                                            <span class="badge badge-active">in use</span>
                                                        @endif
                                                        <a class="btn btn-link"
                                                           href="{{ route('admin.locations.facilities.edit', $facility) }}">Edit</a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2"><p class="empty-state">That LGA has no wards.</p></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>LGA</th>
                                <th>Wards</th>
                                <th>Facilities</th>
                                <th>Registrations</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lgas as $lga)
                                <tr>
                                    <td data-label="LGA">
                                        <a href="{{ route('admin.locations.index', ['lga_id' => $lga->id]) }}">
                                            {{ $lga->name }}
                                        </a>
                                    </td>
                                    <td data-label="Wards">{{ number_format($lga->wards_count) }}</td>
                                    <td data-label="Facilities">{{ number_format($lga->facilities_count) }}</td>
                                    <td data-label="Registrations">{{ number_format($lga->enrolees_count) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4"><p class="empty-state">No locations yet. Import a list to begin.</p></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Add a facility</h2>

        <p class="text-muted">
            Add a health facility to an existing ward. It becomes available in the
            registration form immediately, under the same LGA and ward.
        </p>

        <form method="POST" action="{{ route('admin.locations.facilities.store') }}" data-cascade>
            @csrf

            <div class="grid grid-3">
                <div class="field {{ $errors->has('lga_id') ? 'has-error' : '' }}">
                    <label for="new_lga_id">LGA</label>
                    <select id="new_lga_id" name="lga_id" data-cascade="lga" required>
                        <option value="">Select an LGA</option>
                        @foreach ($lgas as $lga)
                            <option value="{{ $lga->id }}" @selected((int) old('lga_id') === $lga->id)>
                                {{ $lga->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('lga_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('ward_id') ? 'has-error' : '' }}">
                    <label for="new_ward_id">Ward</label>
                    <select id="new_ward_id" name="ward_id" data-cascade="ward" required>
                        <option value="">Select an LGA first</option>
                        @foreach ($formWards as $ward)
                            <option value="{{ $ward->id }}" @selected((int) old('ward_id') === $ward->id)>
                                {{ $ward->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('ward_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('name') ? 'has-error' : '' }}">
                    <label for="new_facility_name">Facility name</label>
                    <input id="new_facility_name" type="text" name="name"
                           value="{{ old('name') }}" maxlength="200" required>
                    <p class="field-hint">Must be unique within the chosen ward.</p>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <span data-submit-label>Add facility</span>
                </button>
            </div>
        </form>
    </div>
@endsection
