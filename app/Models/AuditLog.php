<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'actor_id', 'action', 'subject_type', 'subject_id',
        'program_id', 'description', 'old_values', 'new_values', 'context',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * Canonical action names, so the audit filter UI and any log analysis
     * agree on spelling.
     */
    public const ACTIONS = [
        'login',
        'login_failed',
        'logout',
        'password_changed',
        'user_created',
        'user_updated',
        'user_activated',
        'user_deactivated',
        'user_password_reset',
        'program_created',
        'program_updated',
        'program_deactivated',
        'program_activated',
        'enrolee_created',
        'enrolee_updated',
        'enrolee_submitted',
        'enrolee_deleted',
        'enrolee_photo_viewed',
        'export_requested',
        'export_downloaded',
        'export_download_denied',
        'export_completed',
        'export_failed',
        'locations_imported',
        'facility_created',
        'facility_updated',
        'facility_moved',
    ];

    public function subjectLabel(): string
    {
        if (blank($this->subject_type)) {
            return '—';
        }

        $short = class_basename($this->subject_type);

        return $this->subject_id ? "{$short} #{$this->subject_id}" : $short;
    }
}
