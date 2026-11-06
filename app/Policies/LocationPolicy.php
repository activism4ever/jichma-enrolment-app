<?php

namespace App\Policies;

use App\Models\Lga;
use App\Models\User;

/**
 * Replacing the location list is an administrator capability.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function importLocations(User $user): bool
    {
        return $user->isAdmin();
    }

    public function import(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Adding, renaming and moving a single facility. Kept separate from the
     * import ability so the two can diverge later without changing route
     * behaviour.
     */
    public function manageFacilities(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewAnyLga(User $user, Lga $lga): bool
    {
        return true;
    }
}
