@extends('layouts.app')

@section('title', 'Edit facility')

@section('content')
    <div class="page-header">
        <h1>Edit facility</h1>
        <p class="page-subtitle">
            {{ $facility->name }} &middot; {{ $facility->ward->name }}, {{ $facility->ward->lga->name }}
        </p>
    </div>

    @if ($usageCount > 0)
        <div class="alert alert-info">
            This facility is referenced by {{ number_format($usageCount) }}
            {{ \Illuminate\Support\Str::plural('registration', $usageCount) }}. Its name can be
            corrected, but it cannot be moved to another ward: each registration also records the
            ward and LGA it was made under, and moving the facility would make that record inconsistent.
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ route('admin.locations.facilities.update', $facility) }}" data-cascade>
            @csrf
            @method('PUT')

            <div class="grid grid-3">
                <div class="field {{ $errors->has('lga_id') ? 'has-error' : '' }}">
                    <label for="lga_id">LGA</label>
                    <select id="lga_id" name="lga_id" data-cascade="lga" required
                            @disabled($usageCount > 0)>
                        <option value="">Select an LGA</option>
                        @foreach ($lgas as $lga)
                            <option value="{{ $lga->id }}" @selected($selectedLgaId === $lga->id)>
                                {{ $lga->name }}
                            </option>
                        @endforeach
                    </select>
                    @if ($usageCount > 0)
                        <input type="hidden" name="lga_id" value="{{ $selectedLgaId }}">
                    @endif
                    @error('lga_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('ward_id') ? 'has-error' : '' }}">
                    <label for="ward_id">Ward</label>
                    <select id="ward_id" name="ward_id" data-cascade="ward" required
                            @disabled($usageCount > 0)>
                        <option value="">Select an LGA first</option>
                        @foreach ($wards as $ward)
                            <option value="{{ $ward->id }}" @selected($selectedWardId === $ward->id)>
                                {{ $ward->name }}
                            </option>
                        @endforeach
                    </select>
                    @if ($usageCount > 0)
                        <input type="hidden" name="ward_id" value="{{ $selectedWardId }}">
                    @endif
                    @error('ward_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field {{ $errors->has('name') ? 'has-error' : '' }}">
                    <label for="name">Facility name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name', $facility->name) }}" maxlength="200" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <span data-submit-label>Save facility</span>
                </button>
                <a href="{{ route('admin.locations.index', ['lga_id' => $facility->ward->lga_id]) }}"
                   class="btn btn-link">Cancel</a>
            </div>
        </form>
    </div>
@endsection
