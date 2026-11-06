<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\LocationController as AdminLocationController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnroleeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ProgrammeSummaryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    // Throttled at the route: 5 attempts per minute per IP+email.
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/password', [LoginController::class, 'editPassword'])->name('password.edit');
    Route::put('/password', [LoginController::class, 'updatePassword'])->name('password.update');

    // Everything below is off-limits until the temporary password is replaced.
    Route::middleware('password.changed')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        /* ---------------------------------------------------------- */
        /* Programme summary                                          */
        /* ---------------------------------------------------------- */

        // Enrolment analytics for every authenticated role, scoped exactly like
        // the enrolee list. Authorisation for the detail route is enforced in
        // the controller: the {program} can never widen a user's own program.
        Route::get('/programme-summary', [ProgrammeSummaryController::class, 'index'])->name('programme-summary.index');
        Route::get('/programme-summary/{program}', [ProgrammeSummaryController::class, 'show'])->name('programme-summary.show');

        /* ---------------------------------------------------------- */
        /* Enrolees                                                    */
        /* ---------------------------------------------------------- */

        Route::get('/enrolees', [EnroleeController::class, 'index'])->name('enrolees.index');
        Route::get('/enrolees/create', [EnroleeController::class, 'create'])->name('enrolees.create');
        Route::post('/enrolees', [EnroleeController::class, 'store'])->name('enrolees.store');

        // Submit straight from the form, for a record that does not exist yet.
        // Declared before the /enrolees/{enrolee} routes because "submit" would
        // otherwise be read as an enrolee id.
        Route::post('/enrolees/submit', [EnroleeController::class, 'submitNew'])->name('enrolees.submit-new');

        Route::get('/enrolees/{enrolee}', [EnroleeController::class, 'show'])->name('enrolees.show');
        Route::get('/enrolees/{enrolee}/edit', [EnroleeController::class, 'edit'])->name('enrolees.edit');

        // POST as well as PUT, and the form no longer carries a _method field.
        // Both buttons live in one form: the draft button posts the form's own
        // action, the Submit button overrides it with formaction. A _method
        // field would travel with both, so a Save would arrive spoofed as a PUT
        // to the submit route and 405.
        Route::match(['post', 'put'], '/enrolees/{enrolee}', [EnroleeController::class, 'update'])
            ->name('enrolees.update');

        Route::post('/enrolees/{enrolee}/submit', [EnroleeController::class, 'submit'])->name('enrolees.submit');
        Route::delete('/enrolees/{enrolee}', [EnroleeController::class, 'destroy'])->name('enrolees.destroy');

        // The only route that serves a photograph. Authorisation is applied in
        // the controller, so guessing an id exposes nothing.
        Route::get('/enrolees/{enrolee}/photo', [PhotoController::class, 'show'])->name('enrolees.photo');

        /* ---------------------------------------------------------- */
        /* Cascading location lookups                                 */
        /* ---------------------------------------------------------- */

        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/lgas', [LocationController::class, 'lgas'])->name('lgas');
            Route::get('/lgas/{lga}/wards', [LocationController::class, 'wards'])->name('wards');
            Route::get('/wards/{ward}/facilities', [LocationController::class, 'facilities'])->name('facilities');
        });

        /* ---------------------------------------------------------- */
        /* Administrator screens                                      */
        /* ---------------------------------------------------------- */

        Route::prefix('admin')->name('admin.')->group(function () {
            /* ---------------------------------------------------------- */
            /* Accounts                                                   */
            /* ---------------------------------------------------------- */

            // The UserPolicy plus each FormRequest's authorize() decide
            // access, so listing these routes under auth alone is safe: a
            // non-admin is refused by policy, not by routing.
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');

            Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

            // Deactivation is a soft status change, not a delete: the audit
            // trail references user ids, so accounts are never removed.
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
            Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');

            /* ---------------------------------------------------------- */
            /* Programmes                                                 */
            /* ---------------------------------------------------------- */

            // Programme management is administrator-only, enforced by
            // ProgramPolicy in each action and each form request — listing these
            // routes under auth is safe for the same reason the Users routes
            // above are: a non-admin is refused by policy, not by routing.
            Route::get('/programs', [ProgramController::class, 'index'])->name('programs.index');
            Route::get('/programs/create', [ProgramController::class, 'create'])->name('programs.create');
            Route::post('/programs', [ProgramController::class, 'store'])->name('programs.store');
            Route::get('/programs/{program}/edit', [ProgramController::class, 'edit'])->name('programs.edit');
            Route::put('/programs/{program}', [ProgramController::class, 'update'])->name('programs.update');

            // Deactivation, not deletion: the row is referenced by enrolees,
            // accounts and the audit trail, so it is never removed.
            Route::delete('/programs/{program}', [ProgramController::class, 'destroy'])->name('programs.destroy');
            Route::post('/programs/{program}/activate', [ProgramController::class, 'activate'])->name('programs.activate');

            /* ---------------------------------------------------------- */
            /* Exports                                                    */
            /* ---------------------------------------------------------- */

            // Generation happens in a queued job; these routes record the
            // request and hand the finished file back.
            Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');

            // Throttled because each request queues a real job that reads a
            // slice of the whole table. Without a limit, a borrowed session or a
            // double-clicking admin can fill the queue with a dozen expensive
            // exports nobody is waiting for, and the worker serves those instead
            // of the site's mail and other queued work.
            Route::post('/exports', [ExportController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('exports.store');
            Route::get('/exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
            Route::delete('/exports/{export}', [ExportController::class, 'destroy'])->name('exports.destroy');

            /* ---------------------------------------------------------- */
            /* Audit log                                                  */
            /* ---------------------------------------------------------- */

            // Read-only. There is no route that writes or removes an entry.
            Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

            /* ---------------------------------------------------------- */
            /* Locations                                                  */
            /* ---------------------------------------------------------- */

            Route::get('/locations', [AdminLocationController::class, 'index'])->name('locations.index');
            Route::get('/locations/template', [AdminLocationController::class, 'template'])->name('locations.template');
            Route::post('/locations/import', [AdminLocationController::class, 'import'])->name('locations.import');
            Route::post('/locations/restore', [AdminLocationController::class, 'restoreBundled'])->name('locations.restore');

            // Single-facility management. A facility is never deleted: past
            // registrations point at it, so it is renamed or (when unused)
            // moved instead.
            Route::post('/locations/facilities', [AdminLocationController::class, 'storeFacility'])
                ->name('locations.facilities.store');
            Route::get('/locations/facilities/{facility}/edit', [AdminLocationController::class, 'editFacility'])
                ->name('locations.facilities.edit');
            Route::put('/locations/facilities/{facility}', [AdminLocationController::class, 'updateFacility'])
                ->name('locations.facilities.update');
        });
    });
});
