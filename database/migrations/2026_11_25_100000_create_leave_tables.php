<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: leave. Leave types, yearly balances per employee and paid type, requests, and the ledger of counted leave
 * days (one row per day of an approved request), plus attendances.leave_half for half-day leave.
 *
 * Additive only: runs on a database that already holds employees, users and attendance. The default types are seeded
 * by the next migration (2026_11_25_100100_seed_default_leave_types).
 *
 * Day amounts are multiples of 0.5 in decimal(6,1) (decimal(3,1) for one leave day). Dates are DATE columns holding
 * naive "Y-m-d" values: leave_days.date is read with App\Casts\NaiveDate; request dates are queried with "Y-m-d"
 * strings only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->decimal('yearly_quota', 6, 1)->default(0); // 0-365, halves allowed
            $table->boolean('is_paid')->default(true);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('carry_forward_cap', 6, 1)->default(0);
            $table->boolean('is_active')->default(true);
            // The seeded Unpaid type: never deleted, never made paid (LeaveType guards both).
            $table->boolean('is_system')->default(false);
            $table->string('colour', 20); // config('hrm.leave.colours')
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('allocated', 6, 1)->default(0);
            $table->decimal('adjustment', 6, 1)->default(0); // signed: HR corrections
            $table->decimal('opening_used', 6, 1)->default(0); // used before go-live, entered by HR
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('is_half_day')->default(false);
            $table->string('half', 2)->nullable(); // am | pm, only on a half day (then from_date = to_date)
            $table->decimal('days', 6, 1)->default(0); // counted days; rewritten at final approval
            $table->string('reason', 500);
            $table->string('attachment_path')->nullable(); // private disk
            $table->string('attachment_name')->nullable();
            $table->json('balance_before')->nullable(); // {"2026": 9.5}: available per year when applied
            $table->string('status', 20)->default('pending'); // config('hrm.leave.statuses')
            // Login accounts (users are never deleted, only disabled; null on delete all the same).
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('manager_decided_at')->nullable();
            $table->boolean('manager_skipped')->default(false);
            $table->foreignId('hr_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('hr_decided_at')->nullable();
            $table->string('decision_note', 500)->nullable(); // rejection reason or approval note
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->date('cancelled_from')->nullable(); // approved leave cancelled from this date on
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
            $table->index('from_date');
            $table->index('to_date');
        });

        Schema::create('leave_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 3, 1); // 1.0 or 0.5
            $table->string('half', 4); // full | am | pm
            $table->timestamp('created_at')->nullable();

            $table->unique(['employee_id', 'date', 'half']);
            $table->index('date');
            $table->index(['leave_type_id', 'date']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            // am | pm: the half of the day on leave; status then describes the worked half. Null = no half-day leave.
            $table->string('leave_half', 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('leave_half');
        });

        Schema::dropIfExists('leave_days');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
