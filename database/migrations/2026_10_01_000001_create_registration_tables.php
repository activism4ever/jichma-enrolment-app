<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $t) {
            // Bigint, like every other primary key here. tinyIncrements would
            // cap this table at 255 rows: MySQL wraps a TINYINT UNSIGNED
            // auto-increment counter back to zero, and a national programme
            // adding its fourth or fifth would be a strange way to find out.
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name', 100);
            // Eloquent models enable timestamps by default; the location and
            // program tables need the columns or any model write fails.
            $t->timestamps();
        });

        // Users: role and program assignment.
        //
        // Note on `role`/`program_id` coupling: the database cannot express
        // "admin must have a NULL program" as a foreign key, so that rule is
        // enforced in the AdminUserRequest form request and in the User
        // model's mutator. See User::setProgramIdAttribute().
        Schema::table('users', function (Blueprint $t) {
            $t->enum('role', ['admin', 'program_user', 'reviewer'])
                ->default('program_user')->after('password');
            $t->unsignedBigInteger('program_id')->nullable()->after('role');
            $t->boolean('must_change_password')->default(true)->after('program_id');
            $t->boolean('is_active')->default(true)->after('must_change_password');
            $t->dateTime('last_login_at')->nullable()->after('is_active');
            $t->dateTime('password_changed_at')->nullable()->after('last_login_at');

            $t->foreign('program_id')->references('id')->on('programs');
            $t->index(['role', 'is_active']);
        });

        Schema::create('lgas', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });

        Schema::create('wards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lga_id');
            $t->string('name', 120);
            $t->unique(['lga_id', 'name']);
            $t->foreign('lga_id')->references('id')->on('lgas');
            $t->timestamps();
        });

        Schema::create('facilities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ward_id');
            $t->string('name', 200);
            $t->unique(['ward_id', 'name']);
            $t->foreign('ward_id')->references('id')->on('wards');
            $t->timestamps();
        });

        Schema::create('enrolees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('program_id');
            $t->unsignedBigInteger('created_by');
            $t->enum('status', ['draft', 'submitted'])->default('draft');
            $t->string('surname', 100)->nullable();
            $t->string('first_name', 100)->nullable();
            $t->string('middle_name', 100)->nullable();
            $t->enum('gender', ['male', 'female'])->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('marital_status', 30)->nullable();
            $t->string('education', 60)->nullable();
            $t->string('phone', 20)->nullable();
            // Global duplicate block: a NIN may exist only once across all
            // programs and all statuses, drafts included. NULL is allowed so
            // multiple in-progress drafts may omit it; MySQL permits many NULLs
            // in a unique index. Because deletion is a soft delete, a NIN on a
            // soft-deleted row still occupies the index, which is intentional.
            $t->char('nin', 11)->nullable()->unique();
            $t->string('address', 300)->nullable();
            $t->unsignedBigInteger('lga_id')->nullable();
            $t->unsignedBigInteger('ward_id')->nullable();
            $t->unsignedBigInteger('facility_id')->nullable();
            $t->string('photo_path')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            // The composite index from the starting overlay is kept for the
            // "my drafts" and per-program queries, but it cannot serve a
            // status-only or created_by-only predicate (leftmost-prefix rule),
            // so the single-column indexes the brief asks for are added too.
            $t->index(['program_id', 'created_by', 'status']);
            $t->index('status');
            $t->index('created_by');
            $t->index('lga_id');
            $t->index('ward_id');
            $t->index('facility_id');
            $t->index('submitted_at');

            $t->foreign('program_id')->references('id')->on('programs');
            $t->foreign('created_by')->references('id')->on('users');
            $t->foreign('submitted_by')->references('id')->on('users');
            $t->foreign('lga_id')->references('id')->on('lgas');
            $t->foreign('ward_id')->references('id')->on('wards');
            $t->foreign('facility_id')->references('id')->on('facilities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolees');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('wards');
        Schema::dropIfExists('lgas');
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['program_id']);
            $t->dropColumn(['role', 'program_id', 'must_change_password', 'is_active', 'last_login_at', 'password_changed_at']);
        });
        Schema::dropIfExists('programs');
    }
};