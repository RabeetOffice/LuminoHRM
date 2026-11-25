<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: payroll. Salary profiles (+ components) with history, loans (+ repayments, skips), opening balances at
 * go-live, monthly payroll runs, their lines (payroll_items) and one-off line items (payroll_adjustments).
 *
 * Additive only: runs on a database that already holds employees, users, attendance and leave.
 *
 * Money is integer paisa (1 rupee = 100) in unsigned BIGINT columns (net alone is signed, see payroll_items): never a
 * decimal, never a float. Percentages are
 * integer basis points (500 = 5.00%). Months are "Y-m" strings in CHAR(7). Dates are DATE columns holding naive
 * "Y-m-d" values (App\Casts\NaiveDate). Employee foreign keys restrict the delete (payroll history is kept; see
 * EmployeeAccess::deleteBlockers); user foreign keys null on delete (users are never deleted, only disabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->unsignedBigInteger('basic');
            $table->string('tax_mode', 12); // config('hrm.payroll.tax_modes')
            $table->unsignedBigInteger('tax_value')->default(0); // paisa (fixed), basis points (percentage), 0 (none)
            $table->string('tax_base', 16)->nullable(); // config('hrm.payroll.tax_bases'), percentage only
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 12); // allowance | deduction
            $table->unsignedBigInteger('amount'); // monthly, paisa
            $table->boolean('is_taxable')->default(false); // always false for a deduction
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 12); // loan | advance
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('installment');
            $table->char('start_month', 7); // first deduction month "Y-m"
            $table->unsignedBigInteger('opening_repaid')->default(0); // repaid before go-live (opening loans)
            $table->string('status', 12)->default('active'); // active | cancelled
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tax_year'); // the start year: 2026 = July 2026 to June 2027
            $table->unsignedBigInteger('tax_deducted')->default(0);
            $table->unsignedBigInteger('taxable_income')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'tax_year']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique(); // "Y-m"
            $table->string('status', 12)->default('draft'); // draft | approved | paid
            $table->unsignedInteger('headcount')->default(0);
            $table->unsignedBigInteger('gross')->default(0);
            $table->unsignedBigInteger('deductions')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            // Signed: a draft may hold a line whose net is below zero (it blocks approval), see payroll_items.net.
            $table->bigInteger('net')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->json('summary')->nullable(); // company snapshot, settled-separately and left lists, checks
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete(); // only drafts are ever deleted
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->boolean('excluded')->default(false);
            $table->string('excluded_reason', 255)->nullable();
            $table->unsignedSmallInteger('days_paid')->default(0);
            foreach ([
                'basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
                'other_deductions', 'loan_deduction', 'taxable_pay', 'tax',
            ] as $figure) {
                $table->unsignedBigInteger($figure)->default(0);
            }
            // The one signed money column: a draft line whose deductions exceed its pay stores its negative net and
            // blocks approval ("negative_net"); an approved line is never below zero. Unsigned would make MariaDB
            // refuse the save (SQLite would not notice).
            $table->bigInteger('net')->default(0);
            $table->boolean('tax_overridden')->default(false);
            $table->unsignedBigInteger('tax_override')->nullable();
            $table->string('tax_override_reason', 255)->nullable();
            $table->json('breakdown')->nullable(); // version 1, see the Phase 5 plan; frozen at approval
            $table->json('warnings')->nullable(); // [{key, message}]
            $table->json('blocking')->nullable(); // [key]
            // Bank snapshot taken at approval.
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_number')->nullable();
            $table->string('payslip_path')->nullable(); // private disk
            $table->dateTime('payslip_generated_at')->nullable();
            $table->dateTime('payslip_emailed_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('type', 12); // addition | deduction
            $table->unsignedBigInteger('amount');
            $table->boolean('is_taxable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            // The line that deducted it; null = a manual (cash) repayment recorded by HR.
            $table->foreignId('payroll_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('month', 7);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'payroll_item_id']);
        });

        Schema::create('loan_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_skips');
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('salary_profiles');
    }
};
