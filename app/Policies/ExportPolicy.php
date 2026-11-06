<?php

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * Exports are administrator-only, and an administrator may only download an
 * export they requested themselves. Both are belt-and-braces: the routes are
 * already admin-gated, and the file itself lives outside the web root.
 */
class ExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Export $export): bool
    {
        return $user->isAdmin() && $export->requested_by === $user->id;
    }

    public function download(User $user, Export $export): bool
    {
        return $this->view($user, $export);
    }
}