<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Audit log (spatie/laravel-activitylog). Published from the package and extended with the indexes the
 * viewer's filters use. The timestamp sorts before the access-data migration so that the first Admin
 * created there can be audited on a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            // Viewer: newest first, filtered by date range, area (+ date) and causer.
            $table->index('created_at', 'activity_log_created_at_index');
            $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_at_index');
        });
    }
};
