<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: attendance. Shifts, machines, raw punches, machine commands, the daily attendance record, correction
 * requests and the holiday calendar, plus the employee's shift and attendance-exempt flag.
 *
 * Runs on a database that already has employees and users: the two new employee columns are nullable / defaulted.
 *
 * Time columns:
 *   - "naive" DATETIME columns (device_punches.punch_time, attendances.clock_in/clock_out, devices.last_punch_at) hold the
 *     machine's local wall-clock time exactly as received. DATETIME (never TIMESTAMP) so the database session zone never
 *     converts them; the models use App\Casts\NaiveDateTime so Eloquent does not either.
 *   - TIME columns (shift start/end, requested in/out) are read and written as "H:i".
 *   - devices.last_seen_at and device_commands.sent_at are ordinary app-time datetimes (when the server saw something).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->decimal('half_day_hours', 4, 2);
            $table->json('weekly_offs'); // ["saturday","sunday"]: lowercase English day names
            // Exactly one row is true; enforced by Shift (saving guard + Shift::makeDefault()).
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('serial_no', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable(); // app time
            $table->string('last_stamp', 50)->nullable(); // the machine's ATTLOGStamp
            $table->dateTime('last_punch_at')->nullable(); // naive machine time
            $table->timestamps();
        });

        Schema::create('device_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('device_user_id', 20);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('punch_time'); // naive machine time
            $table->date('punch_date'); // the date part of punch_time, set by DevicePunch
            $table->string('verify_type', 10)->nullable();
            $table->string('status_code', 10)->nullable();
            $table->string('source', 10)->default('device'); // device | import | manual
            $table->timestamp('created_at')->nullable();

            $table->unique(['device_id', 'device_user_id', 'punch_time']);
            $table->index(['employee_id', 'punch_date']);
            $table->index('device_user_id');
            $table->index('punch_date');
        });

        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->text('command');
            $table->string('status', 10)->default('queued'); // queued | sent | done | failed
            $table->dateTime('sent_at')->nullable(); // app time
            $table->text('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });

        Schema::table('employees', function (Blueprint $table) {
            // Null means "the default shift".
            $table->foreignId('shift_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
            $table->boolean('attendance_exempt')->default(false)->after('shift_id');
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            // Attendance history blocks the hard deletion of an employee (EmployeeAccess::deleteBlockers).
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete(); // the shift used for this day
            $table->dateTime('clock_in')->nullable(); // naive machine time
            $table->dateTime('clock_out')->nullable(); // naive machine time
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            // config('hrm.attendance.statuses'); null = pending calculation (a bare row made by punch ingestion).
            $table->string('status', 20)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->boolean('needs_attention')->default(false);
            $table->boolean('needs_rebuild')->default(false);
            $table->boolean('closed')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
            $table->index('needs_rebuild');
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('requested_in')->nullable();
            $table->time('requested_out')->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending'); // config('hrm.attendance.correction_statuses')
            // Who decided: login accounts (users are never deleted, only disabled).
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('manager_decided_at')->nullable();
            $table->foreignId('hr_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('hr_decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
            $table->index('status');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('date')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendances');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn('attendance_exempt');
        });

        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('device_punches');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('shifts');
    }
};
