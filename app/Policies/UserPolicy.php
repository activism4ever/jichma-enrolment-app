<?php

namespace App\Policies;

use App\Models\User;

/**
 * User administration is a single-role capability.
 *
 * Admin only, for create/edit/deactivate/reset. The rules also guard against a
 * non-admin acting on any user, including themselves: a Program User must not
 * be able to escalate themselves by editing their own account.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Deactivating an account. An administrator cannot deactivate their own
     * account, which is the standard way to avoid locking the last admin out.
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    public function activate(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /** Issuing a temporary password. */
    public function resetPassword(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}