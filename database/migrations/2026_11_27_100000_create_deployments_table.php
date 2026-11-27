<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System updates (Updates screen): one row per click of "Update system", with its step-by-step log, the database changes
 * that were waiting and the ones it applied. Additive only. Users are never deleted, so the user key nulls on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('update')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('release', 50)->nullable();
            $table->longText('log')->nullable();
            $table->json('pending_migrations')->nullable();
            $table->json('migrations_run')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
