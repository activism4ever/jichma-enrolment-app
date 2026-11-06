<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a running export something to report.
     *
     * Without these, an export that takes minutes looks identical to one that has
     * wedged: the row says "processing" and nothing else, so the only honest
     * thing an administrator can do is wait and guess. Counting rows as they are
     * written turns that into an answer, and it costs one indexed UPDATE per
     * 500-row page rather than per row.
     */
    public function up(): void
    {
        Schema::table('exports', function (Blueprint $t) {
            $t->unsignedInteger('processed_count')->nullable()->after('row_count');
            $t->unsignedInteger('total_count')->nullable()->after('processed_count');
        });
    }

    public function down(): void
    {
        Schema::table('exports', function (Blueprint $t) {
            $t->dropColumn(['processed_count', 'total_count']);
        });
    }
};
