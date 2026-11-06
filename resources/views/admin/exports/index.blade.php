@extends('layouts.app')

@section('title', 'Export')

@section('content')
    <div class="page-header">
        <h1>Export</h1>
        <p class="page-subtitle">
            Exports contain passport photographs and national identification numbers.
            Each file is stored outside the web root, can only be downloaded by the
            administrator who requested it, and is deleted {{ config('exports.retain_hours', 24) }} hours after it is ready.
        </p>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2 class="card-title">Request an export</h2>

            <form method="POST" action="{{ route('admin.exports.store') }}">
                @csrf

                <div class="field {{ $errors->has('kind') ? 'has-error' : '' }}">
                    <label for="kind">Format</label>
                    <select id="kind" name="kind" required>
                        <option value="csv" @selected(old('kind') === 'csv')>CSV &mdash; one row per enrolee, opens in any spreadsheet</option>
                        <option value="xlsx" @selected(old('kind') === 'xlsx')>XLSX &mdash; Excel workbook</option>
                        <option value="photos" @selected(old('kind') === 'photos')>Photos (ZIP) &mdash; photographs only, no NINs</option>
                    </select>
                    @error('kind') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('program_id') ? 'has-error' : '' }}">
                    <label for="program_id">Program</label>
                    <select id="program_id" name="program_id">
                        <option value="">All programs</option>
                        @foreach ($programs as $program)
                            <option value="{{ $program->id }}" @selected((int) old('program_id') === $program->id)>
                                {{ $program->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="field-hint">Leave as &ldquo;All programs&rdquo; to include every program.</p>
                    @error('program_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-2">
                    <div class="field {{ $errors->has('status') ? 'has-error' : '' }}">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">Drafts and submitted</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status') === $status->value)>
                                    {{ $status->label() }} only
                                </option>
                            @endforeach
                        </select>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="search">Search</label>
                        <input id="search" type="search" name="search" value="{{ old('search') }}"
                               autocomplete="off"
                               placeholder="Name, NIN or phone">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="field {{ $errors->has('from') ? 'has-error' : '' }}">
                        <label for="from">Registered from</label>
                        <input id="from" type="date" name="from" value="{{ old('from') }}">
                        @error('from') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field {{ $errors->has('to') ? 'has-error' : '' }}">
                        <label for="to">Registered to</label>
                        <input id="to" type="date" name="to" value="{{ old('to') }}">
                        @error('to') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="field">
                    <label for="lga_id">LGA</label>
                    <select id="lga_id" name="lga_id">
                        <option value="">All LGAs</option>
                        @foreach ($lgas as $lga)
                            <option value="{{ $lga->id }}" @selected((int) old('lga_id') === $lga->id)>
                                {{ $lga->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($filterChips !== [])
                    {{-- The count below is only meaningful next to what is being
                         counted, so each active filter is stated as a chip that
                         removes itself and nothing else. --}}
                    <div class="filter-chips" role="group" aria-label="Active filters">
                        <span class="filter-chips-label">Active filters:</span>
                        @foreach ($filterChips as $chip)
                            <span class="chip">
                                {{ $chip['label'] }}
                                <a class="chip-remove"
                                   href="{{ $chip['remove_url'] }}"
                                   title="Remove this filter"
                                   aria-label="Remove filter: {{ $chip['label'] }}">&times;</a>
                            </span>
                        @endforeach
                        <a class="chip-clear" href="{{ route('admin.exports.index') }}">Clear all</a>
                    </div>
                @endif

                <div class="alert alert-info">
                    @if ($filters->toArray() === [])
                        {{ number_format($counts['matched']) }} enrolees in total.
                    @else
                        {{ number_format($counts['matched']) }} enrolees match the current filters.
                    @endif
                    Of those, {{ number_format($counts['submitted']) }} are submitted and
                    {{ number_format($counts['photos']) }} have a photograph.

                    @if (($filters->programId === null) && $filters->status === null)
                        <br>
                        An unfiltered CSV contains every NIN in the system. Keep the file
                        as carefully as the source data.
                    @endif
                </div>

                {{-- No password is asked for here. Re-typing it protected nothing
                     this page does not already protect: the route is
                     admin-only, the filters are re-applied against the
                     requester's own visibility when the job runs, and every
                     request and download is written to the audit trail. It cost
                     a second of friction on the one action an administrator
                     legitimately performs many times a day, and it trained people
                     to type their password into a page that had no reason to
                     ask for it. --}}
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <span data-submit-label>Queue export</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title">Your exports</h2>

            @if ($busy)
                <div class="alert alert-info">
                    An export is still being generated. This page refreshes itself
                    every 10 seconds until it finishes; nothing else needs doing.
                </div>
            @endif

            @if ($exports->isEmpty())
                <p class="text-muted">You have not requested any exports yet.</p>
            @else
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Created</th>
                                <th>Format</th>
                                <th>Status</th>
                                <th>Filters used</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($exports as $export)
                                <tr>
                                    <td data-label="Created" class="nowrap text-small">
                                        {{ $export->created_at?->format('j M Y H:i') }}
                                    </td>
                                    <td data-label="Format" class="mono text-small">{{ strtoupper($export->kind) }}</td>
                                    <td data-label="Status">
                                        @if ($export->status === 'ready' && ! $export->hasExpired())
                                            <span class="badge badge-active">Ready</span>
                                        @elseif ($export->status === 'failed')
                                            <span class="badge badge-inactive">Failed</span>
                                        @elseif ($export->status === 'processing')
                                            <span class="badge badge-draft">Processing</span>
                                        @elseif ($export->status === 'pending')
                                            <span class="badge badge-draft">Pending</span>
                                        @elseif ($export->hasExpired())
                                            <span class="badge badge-draft">Expired</span>
                                        @else
                                            <span class="badge badge-draft">{{ ucfirst($export->status) }}</span>
                                        @endif

                                        {{-- A running export says how far it has got.
                                             Without this, "Processing" is
                                             indistinguishable from "wedged", and
                                             the only thing an administrator can
                                             do is wait and wonder. --}}
                                        @if ($export->isActive() && $export->progressLabel())
                                            <p class="text-small text-muted" data-export-progress>
                                                {{ $export->progressLabel() }}
                                                @if ($percent = $export->progressPercent())
                                                    <span class="text-muted">({{ $percent }}%)</span>
                                                @endif
                                            </p>
                                        @elseif ($export->status === 'pending')
                                            <p class="text-small text-muted">Waiting for a worker</p>
                                        @endif

                                        @if ($export->status === 'failed' && $export->error)
                                            <p class="text-small text-muted">{{ $export->error }}</p>
                                        @endif

                                        @if ($export->isDownloadable())
                                            <p class="text-small text-muted">
                                                {{ number_format($export->row_count) }} rows &middot; {{ $export->humanSize() }}
                                            </p>
                                        @endif
                                    </td>
                                    {{-- The filters are stored as ids so the queued
                                         job can re-apply them; this is the same set
                                         resolved back into words, because "who
                                         downloaded what" has to be answerable from
                                         the audit trail and the screen alike. --}}
                                    <td data-label="Filters used" class="text-small">{{ $export->filterSummary() }}</td>
                                    <td data-label="Size" class="nowrap text-small">{{ $export->humanSize() }}</td>
                                    <td data-label="Actions">
                                        <div class="cell-actions">
                                            @if ($export->isDownloadable())
                                                {{-- @can kept even though this list
                                                     is already scoped to the
                                                     requester: the controller
                                                     authorises too, and defence
                                                     in depth is free here. --}}
                                                @can('download', $export)
                                                    <a class="btn btn-sm btn-primary"
                                                       href="{{ route('admin.exports.download', $export) }}">Download</a>
                                                @endcan
                                            @else
                                                <span class="text-muted text-small">
                                                    @if ($export->status === 'ready')
                                                        Expired &mdash; request it again
                                                    @else
                                                        Not available yet
                                                    @endif
                                                </span>
                                            @endif

                                            <form method="POST" action="{{ route('admin.exports.destroy', $export) }}"
                                                  class="inline-form"
                                                  data-confirm="Discard this export? The generated file is deleted and cannot be recovered.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Discard</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('partials.pagination', ['paginator' => $exports])
            @endif
        </div>
    </div>

    {{-- What was produced, and by whom. Both blocks read the exports table
         itself rather than the audit trail: the row carries the requester,
         the filters as submitted, the format and the record count, which is
         exactly the five columns below, and it is written whether or not
         anyone reads this screen. Failed and pending requests are absent
         from the history deliberately — they produced nothing to account
         for, and they are still visible in "Your exports" above. --}}
    <div class="card">
        <h2 class="card-title">Last export</h2>

        @if ($lastExport === null)
            <p class="text-muted">No export yet</p>
        @else
            <p>
                <strong>{{ $lastExport->formatLabel() }}</strong>
                &middot; {{ number_format((int) $lastExport->row_count) }} records
                &middot; {{ $lastExport->created_at?->format('d M Y, H:i') }}
            </p>

            <p class="text-small text-muted">
                Requested by {{ $lastExport->requester?->name ?? 'an account that no longer exists' }}
                &middot; Scope: {{ $lastExport->filterSummary() }}
                &middot; {{ $lastExport->humanSize() }}

                @if ($lastExport->hasExpired())
                    &middot; The download link has expired.
                @elseif ($lastExport->expires_at !== null)
                    &middot; The download link works until {{ $lastExport->expires_at->format('j M Y H:i') }}.
                @endif
            </p>

            @if ($lastExport->isDownloadable())
                {{-- Authorised per row: this card shows the newest export
                     across all administrators, and only its requester is
                     ever offered the file. --}}
                @can('download', $lastExport)
                    <a class="btn btn-primary btn-sm"
                       href="{{ route('admin.exports.download', $lastExport) }}">Download</a>
                @endcan
            @endif
        @endif
    </div>

    <div class="card">
        <h2 class="card-title">Export history</h2>

        @if ($history->isEmpty())
            <p class="text-muted">No exports have been performed yet.</p>
        @else
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Exported By</th>
                            <th>Scope</th>
                            <th>Records</th>
                            <th>Format</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $export)
                            <tr>
                                <td data-label="Date &amp; Time" class="nowrap text-small">
                                    {{ $export->created_at?->format('d M Y, H:i') }}
                                </td>
                                <td data-label="Exported By">{{ $export->requester?->name ?? '—' }}</td>
                                <td data-label="Scope" class="text-small">{{ $export->filterSummary() }}</td>
                                <td data-label="Records" class="nowrap">
                                    {{ number_format((int) $export->row_count) }}
                                </td>
                                <td data-label="Format" class="nowrap">{{ $export->formatLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($history->count() === $historyLimit)
                <p class="text-small text-muted">
                    The {{ number_format($historyLimit) }} most recent completed exports.
                </p>
            @endif
        @endif
    </div>
@endsection
