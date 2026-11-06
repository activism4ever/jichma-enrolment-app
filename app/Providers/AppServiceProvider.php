<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\EnroleePolicy;
use App\Policies\ExportPolicy;
use App\Policies\LocationPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Explicit policy registration. Laravel 11 also discovers these by
        // convention, but naming them keeps the mapping obvious and immune to
        // a file move.
        Gate::policy(Enrolee::class, EnroleePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Export::class, ExportPolicy::class);
        Gate::policy(Lga::class, LocationPolicy::class);
        Gate::policy(Program::class, ProgramPolicy::class);

        Gate::define('access-admin-area', fn (User $user) => $user->isAdmin());
    }
}