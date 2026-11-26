<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b: performance. KPIs, review cycles, one review per person per cycle, and the frozen copy of each person's KPIs
 * (review_scores) taken when the review is created.
 *
 * Additive only. Scores are integer hundredths (412 = 4.12) in unsigned SMALLINTs, ratings whole numbers 1 to 5, weights
 * whole numbers 1 to 100: no decimal or float column. Dates are DATE columns holding naive "Y-m-d" values
 * (App\Casts\NaiveDate). Foreign keys restrict the delete (cycles, reviews and the KPIs they used are history); user
 * foreign keys null on delete (users are never deleted, only disabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedTinyInteger('weight');
            $table->boolean('is_active')->default(true);
            $table->foreignId('designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('period_type', 10); // config('hrm.performance.period_types')
            $table->string('period_key', 10)->unique(); // 2026-Q3, 2026-11
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->string('status', 10)->default('open'); // config('hrm.performance.cycle_states')
            $table->boolean('self_review_enabled');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'period_end']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_cycle_id')->constrained('review_cycles')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('status', 14); // config('hrm.performance.review_states')
            $table->unsignedSmallInteger('self_score')->nullable(); // hundredths
            $table->unsignedSmallInteger('final_score')->nullable(); // hundredths
            $table->string('manager_comment', 2000)->nullable();
            $table->string('send_back_note', 500)->nullable();
            $table->string('skip_reason', 500)->nullable();
            $table->string('skipped_from', 14)->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index('status');
            $table->index(['reviewer_id', 'status']);
        });

        Schema::create('review_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->restrictOnDelete();
            $table->foreignId('kpi_id')->nullable()->constrained('kpis')->restrictOnDelete();
            // The snapshot: frozen when the review is created.
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedTinyInteger('weight');
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->string('self_comment', 1000)->nullable();
            $table->string('manager_comment', 1000)->nullable();
            $table->timestamps();

            $table->index(['review_id', 'sort_order']);
            $table->index('kpi_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_scores');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_cycles');
        Schema::dropIfExists('kpis');
    }
};
