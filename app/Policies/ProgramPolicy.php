<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

/**
 * Programme administration is a single-role capability.
 *
 * Admin only, for create/rename/sensitivity/deactivate. Everyone else is
 * refused by policy, not merely by a hidden navigation link, so the rules hold
 * however the route is reached.
 */
class ProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Program $program): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Rename, edit the description and change the sensitivity setting. The
     * programme code is immutable and is not part of this action.
     */
    public function update(User $user, Program $program): bool
    {
        return $user->isAdmin();
    }

    /**
     * Taking a programme out of service. Deactivation only: the row is never
     * deleted, because users, enrolees and the audit trail reference it.
     */
    public function deactivate(User $user, Program $program): bool
    {
        return $user->isAdmin();
    }

    public function activate(User $user, Program $program): bool
    {
        return $user->isAdmin();
    }
}
