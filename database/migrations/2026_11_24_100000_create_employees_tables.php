<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: employees and their three satellite tables, plus the two links back from users and departments.
 *
 * Runs after every Phase 0-1 migration, including the data migration that creates the first admin through the
 * live User model: users.employee_id is therefore nullable with no default, and stays nullable (an admin may
 * have no employee record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->unique();
            // The ID the attendance machine knows the person by.
            $table->string('device_user_id', 50)->nullable()->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('photo_path')->nullable();
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 10)->nullable(); // male | female | other
            $table->string('cnic', 13)->nullable()->unique(); // 13 digits, no dashes
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joining_date');
            $table->date('probation_end_date')->nullable();
            $table->string('employment_type', 20); // full_time | part_time | contract | intern
            $table->string('status', 20); // active | probation | inactive | resigned | terminated
            $table->date('last_working_day')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('department_id');
            $table->index('designation_id');
            $table->index('manager_id');
        });

        Schema::create('employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('address')->nullable();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_number')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // cnic | contract | offer_letter | certificate | other
            $table->string('title');
            $table->string('file_path'); // private disk, random stored name
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->date('expiry_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->after('id')->constrained('employees')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_employee_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_contacts');
        Schema::dropIfExists('employees');
    }
};
