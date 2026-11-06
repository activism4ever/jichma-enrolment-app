<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            // Nullable: failed logins are recorded before any user is known.
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 60);
            // Polymorphic subject, e.g. Enrolee or User, without a FK so that
            // an audit row survives deletion of its subject.
            $t->string('subject_type', 60)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('program_id')->nullable();
            $t->text('description')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();

            $t->index(['actor_id', 'created_at']);
            $t->index('action');
            $t->index(['subject_type', 'subject_id']);
            $t->index('program_id');
            $t->index('created_at');
            $t->foreign('actor_id')->references('id')->on('users');
            $t->foreign('program_id')->references('id')->on('programs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};