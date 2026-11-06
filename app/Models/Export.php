<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per admin export request. Doubles as the authority for whether a
 * generated file may still be downloaded.
 */
class Export extends Model
{
    protected $fillable = [
        'uuid', 'requested_by', 'kind', 'status', 'disk', 'path',
        'size_bytes', 'row_count', 'processed_count', 'total_count',
        'filters', 'error', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'processed_count' => 'integer',
            'total_count' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public static function newUuid(): string
    {
        return (string) Str::uuid();
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** A download is allowed only for a ready, unexpired, non-empty export. */
    public function isDownloadable(): bool
    {
        return $this->isReady() && ! $this->hasExpired() && filled($this->path);
    }

    /** True while the artefact is still being built. */
    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    /**
     * Progress as a person reads it: "Processed 1,200 of 5,000".
     *
     * Null when the total is not known yet, which is the honest answer for a job
     * that has been queued but not picked up — a percentage here would be a
     * guess wearing a decimal point.
     */
    public function progressLabel(): ?string
    {
        if ($this->total_count === null) {
            return null;
        }

        return sprintf(
            'Processed %s of %s',
            number_format((int) $this->processed_count),
            number_format((int) $this->total_count)
        );
    }

    /** Completion as a whole percentage, or null when the total is unknown. */
    public function progressPercent(): ?int
    {
        if (! $this->total_count) {
            return null;
        }

        return (int) min(100, round(((int) $this->processed_count / (int) $this->total_count) * 100));
    }

    public function humanSize(): string
    {
        if (! $this->size_bytes) {
            return '—';
        }

        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    /**
     * The format as a person names it: "Excel" rather than the stored "xlsx",
     * which is a filename suffix rather than a word anyone says out loud.
     */
    public function formatLabel(): string
    {
        return match ($this->kind) {
            'csv' => 'CSV',
            'xlsx' => 'Excel',
            'photos' => 'Photos (ZIP)',
            default => strtoupper($this->kind),
        };
    }

    /**
     * The filters this export was queued with, in words.
     *
     * The stored filters are ids, which are the right thing to re-run the query
     * with but the wrong thing to show a person three weeks later asking what
     * they downloaded. Read-only lookup by id rather than a snapshot of the
     * label: a renamed program is shown under its current name, and a deleted
     * one still shows its id, which is more useful than nothing.
     *
     * Says "All programs" for an unfiltered export rather than an empty string,
     * because "no filters" is a meaningful and consequential choice.
     */
    public function filterSummary(): string
    {
        $filters = $this->filters ?? [];

        $parts = [];

        if ($value = $filters['program_id'] ?? null) {
            $parts[] = 'Program: '.($this->nameFor(Program::class, $value) ?? "id {$value}");
        }

        if ($value = $filters['status'] ?? null) {
            $parts[] = 'Status: '.ucfirst($value);
        }

        if ($value = $filters['lga_id'] ?? null) {
            $parts[] = 'LGA: '.($this->nameFor(Lga::class, $value) ?? "id {$value}");
        }

        if ($value = $filters['ward_id'] ?? null) {
            $parts[] = 'Ward: '.($this->nameFor(Ward::class, $value) ?? "id {$value}");
        }

        if ($value = $filters['facility_id'] ?? null) {
            $parts[] = 'Facility: '.($this->nameFor(Facility::class, $value) ?? "id {$value}");
        }

        if ($value = $filters['created_by'] ?? null) {
            $parts[] = 'Created by: '.($this->nameFor(User::class, $value) ?? "id {$value}");
        }

        foreach (['from' => 'From', 'to' => 'To'] as $key => $label) {
            if ($value = $filters[$key] ?? null) {
                $parts[] = $label.': '.$value;
            }
        }

        if ($value = $filters['search'] ?? null) {
            $parts[] = 'Search: "'.$value.'"';
        }

        return $parts === [] ? 'All programs' : implode(' · ', $parts);
    }

    /**
     * Resolve a stored id to a label, or null if the row has since gone.
     *
     * Programs are labelled through Program::label() so this reads "CEMOG"
     * rather than repeating the name the code already implies.
     */
    private function nameFor(string $model, int|string $id): ?string
    {
        $row = $model::find($id);

        if (! $row) {
            return null;
        }

        return $row instanceof Program ? $row->label() : ($row->name ?? null);
    }
}