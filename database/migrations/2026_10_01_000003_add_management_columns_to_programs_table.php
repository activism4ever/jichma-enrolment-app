<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the programme list administrable.
     *
     * Three columns are added, with defaults chosen so existing installations
     * keep behaving exactly as before:
     *
     *   is_active    - active unless someone deactivates it. Programmes are
     *                  never deleted (users, enrolees and the audit trail all
     *                  reference them), so deactivation is the whole lifecycle.
     *   is_sensitive - replaces the hard-coded `code === 'PLHIV'` check. Every
     *                  programme defaults to non-sensitive; the existing PLHIV
     *                  row is then explicitly marked sensitive below so the
     *                  data-protection it already had is preserved rather than
     *                  silently dropped the moment the check reads a column.
     *   description  - optional free text.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            $t->boolean('is_active')->default(true)->after('name');
            $t->boolean('is_sensitive')->default(false)->after('is_active');
            $t->string('description', 500)->nullable()->after('is_sensitive');
        });

        // Preserve the protection PLHIV had before sensitivity became data.
        // Matched on code, which is immutable, so this cannot move to another
        // programme between deploy and migration.
        DB::table('programs')
            ->where('code', 'PLHIV')
            ->update(['is_sensitive' => true]);
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            $t->dropColumn(['description', 'is_sensitive', 'is_active']);
        });
    }
};
