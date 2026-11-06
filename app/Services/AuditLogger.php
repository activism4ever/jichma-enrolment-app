<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrolee;
use Illuminate\Http\Request;

/**
 * Central writer for the audit trail.
 *
 * Every sensitive action funnels through here so that the set of recorded
 * actions stays consistent: the Nigeria Data Protection Act requires an
 * accountable record of processing of personal data, and a trail assembled by
 * hand at each call site invariably has gaps.
 */
class AuditLogger
{
    /**
     * @param  object|null  $actor  Who performed the action, when it is not
     *                              the signed-in user. A queued job runs with
     *                              no session, and an export generated on
     *                              someone's behalf still has to be
     *                              attributable to them in the trail.
     */
    public function log(
        string $action,
        ?string $description = null,
        ?object $subject = null,
        array $old = [],
        array $new = [],
        array $context = [],
        ?Request $request = null,
        ?object $actor = null,
    ): void {
        // Never let audit bookkeeping break the request it is describing.
        try {
            $actor ??= auth()->user();
            $request ??= request();

            AuditLog::create([
                'user_id' => $actor?->id,
                'actor_id' => $actor?->id,
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->id,
                'program_id' => $this->programIdFor($subject, $context),
                'description' => $description,
                'old_values' => $old ?: null,
                'new_values' => $new ?: null,
                'context' => $context ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $this->truncateUserAgent($request?->userAgent()),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function login(Request $request, object $user): void
    {
        $this->log('login', "{$user->name} signed in", null, [], [
            'email' => $user->email,
            'role' => $user->role->value,
        ], [], $request);
    }

    public function loginFailed(Request $request, string $email): void
    {
        // No actor: the attempt failed. Recorded without the password.
        $this->log('login_failed', 'Failed sign-in attempt', null, [], [
            'email' => $email,
        ], [], $request);
    }

    public function logout(Request $request, object $user): void
    {
        $this->log('logout', "{$user->name} signed out", null, [], [
            'email' => $user->email,
        ], [], $request);
    }

    /**
     * Diff two snapshots, keeping only the keys that actually changed.
     * Makes the audit row readable and avoids storing an unchanged row twice.
     */
    public static function diff(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) && $before[$key] !== $value) {
                $changed[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }

        return $changed;
    }

    private function programIdFor(?object $subject, array $context): ?int
    {
        if ($subject instanceof Enrolee) {
            return $subject->program_id;
        }

        if (isset($context['program_id'])) {
            return $context['program_id'];
        }

        return null;
    }

    private function truncateUserAgent(?string $agent): ?string
    {
        if ($agent === null) {
            return null;
        }

        return mb_substr($agent, 0, 500);
    }
}
