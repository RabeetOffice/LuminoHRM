<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a: assets. Categories, the asset register and the hand-over history (assignments).
 *
 * Additive only: runs on a database that already holds employees and users. Cost is integer paisa in an unsigned BIGINT
 * (never a decimal or a float). Dates are DATE columns holding naive "Y-m-d" values (App\Casts\NaiveDate). Foreign keys
 * restrict the delete (an asset's history is kept; an employee with assignment rows cannot be deleted); user foreign keys
 * null on delete (users are never deleted, only disabled).
 *
 * asset_assignments.open_asset_id holds the asset id while the assignment is open (returned_on is null) and NULL once it
 * is returned. Its unique index is the database backstop for "an asset is held by one person at a time": a second open
 * row for the same asset is refused, while any number of returned rows (NULL) coexist. The model keeps it in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 20)->unique();
            $table->string('name', 120);
            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->date('purchase_date')->nullable();
            $table->unsignedBigInteger('cost')->nullable(); // paisa
            $table->string('condition', 10); // config('hrm.assets.conditions')
            $table->string('status', 12)->default('available'); // config('hrm.assets.statuses')
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('asset_category_id');
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('assigned_on');
            $table->date('returned_on')->nullable();
            $table->string('condition_out', 10);
            $table->string('return_condition', 10)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('return_note', 500)->nullable();
            $table->unsignedBigInteger('open_asset_id')->nullable()->unique(); // = asset_id while open, NULL once returned
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'returned_on']);
            $table->index(['asset_id', 'assigned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
