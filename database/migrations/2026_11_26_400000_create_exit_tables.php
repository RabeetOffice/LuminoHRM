<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6c: exit. Resignations (one row per exit), clearance checklist rows, the final settlement and its lines, and the
 * link from a loan repayment to the settlement that recovered it.
 *
 * Additive only: runs on a database that already holds employees, users, payroll and loans. Money is integer paisa in an
 * unsigned BIGINT, except final_settlements.net, which is signed (a settlement may end below zero: the employee owes).
 * Dates are DATE columns holding naive "Y-m-d" values (App\Casts\NaiveDate). Employee foreign keys restrict the delete
 * (exit history is kept); user foreign keys null on delete (users are never deleted, only disabled).
 *
 * resignations.open_employee_id holds the employee id while the exit is open (pending, manager_approved, approved) and
 * NULL once it has ended. Its unique index is the database backstop for "one open exit per person": a second open row
 * for the same employee is refused, while any number of ended rows (NULL) coexist. The model keeps it in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 12); // config('hrm.exit.types')
            $table->date('submitted_on');
            $table->date('last_working_day');
            $table->string('reason', 1000);
            $table->unsignedSmallInteger('notice_period_days')->default(0); // the setting at the time of submitting
            $table->string('status', 16)->default('pending'); // config('hrm.exit.statuses')
            $table->unsignedBigInteger('open_employee_id')->nullable()->unique(); // = employee_id while open, else NULL
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // HR recorded it
            $table->boolean('manager_skipped')->default(false);
            $table->foreignId('manager_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('manager_decided_at')->nullable();
            $table->foreignId('final_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('final_decided_at')->nullable();
            $table->boolean('own_decision')->default(false); // the sole active Admin decided their own exit
            $table->string('decision_note', 500)->nullable(); // the rejection note
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->dateTime('status_applied_at')->nullable(); // the nightly job changed the status
            $table->string('status_error', 255)->nullable(); // why the nightly job could not (retried each run)
            $table->text('letter_text')->nullable(); // the experience letter as issued
            $table->foreignId('letter_issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('letter_issued_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
            $table->index('last_working_day');
        });

        Schema::create('clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resignation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // config('hrm.exit.clearance_kinds')
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete(); // NULL with cleared_at = automatic
            $table->dateTime('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['resignation_id', 'sort_order']);
        });

        Schema::create('final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resignation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->char('month', 7); // "Y-m" of the last working day
            $table->string('status', 10)->default('draft'); // config('hrm.exit.settlement_statuses')
            foreach ([
                'basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
                'other_deductions', 'loan_deduction', 'taxable_pay', 'tax',
            ] as $figure) {
                $table->unsignedBigInteger($figure)->default(0);
            }
            $table->bigInteger('net')->default(0); // signed: below zero = owed by the employee
            $table->unsignedInteger('encashment_days')->default(0); // in halves (13 = 6.5 days)
            $table->unsignedBigInteger('encashment_amount')->default(0);
            $table->unsignedBigInteger('loan_shortfall')->default(0);
            $table->boolean('tax_overridden')->default(false);
            $table->unsignedBigInteger('tax_override')->nullable();
            $table->string('tax_override_reason', 255)->nullable();
            $table->json('breakdown')->nullable();
            $table->boolean('stale')->default(false); // the last working day moved: recalculate before approving
            $table->dateTime('calculated_at')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('own_settlement')->default(false);
            $table->date('paid_on')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable(); // company header and footer snapshot at approval
            $table->string('statement_path', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'month']);
            $table->index('status');
        });

        Schema::create('final_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('final_settlement_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // config('hrm.exit.line_kinds')
            $table->string('type', 10); // addition | deduction
            $table->string('label', 100);
            $table->unsignedBigInteger('amount'); // paisa
            $table->boolean('is_taxable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            // The settlement that recovered this repayment; null for a payroll or manual repayment.
            $table->foreignId('final_settlement_id')->nullable()->after('payroll_item_id')
                ->constrained('final_settlements')->restrictOnDelete();
            $table->unique(['loan_id', 'final_settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropUnique(['loan_id', 'final_settlement_id']);
            $table->dropConstrainedForeignId('final_settlement_id');
        });

        Schema::dropIfExists('final_settlement_lines');
        Schema::dropIfExists('final_settlements');
        Schema::dropIfExists('clearances');
        Schema::dropIfExists('resignations');
    }
};
