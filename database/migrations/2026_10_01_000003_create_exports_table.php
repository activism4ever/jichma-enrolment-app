<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks each admin export request and the artefact it produced, so a
        // download link can be offered that expires and cannot be re-issued
        // indefinitely.
        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('requested_by');
            $t->string('kind', 20); // csv | xlsx | photos
            $t->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $t->string('disk', 40)->default('private');
            $t->string('path')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->unsignedInteger('row_count')->nullable();
            $t->json('filters')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['requested_by', 'created_at']);
            $t->index(['status', 'created_at']);
            $t->foreign('requested_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};