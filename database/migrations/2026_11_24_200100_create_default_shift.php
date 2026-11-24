<?php

use App\Support\Attendance\DefaultShift;
use Illuminate\Database\Migrations\Migration;

// Data migration: production runs `migrate --force` but never seeds. Idempotent: does nothing when a default shift exists.
// Creates "General" from the attendance settings (or their defaults). Query builder only, no model events or audit entry.
return new class extends Migration
{
    public function up(): void
    {
        DefaultShift::ensure();
    }

    public function down(): void
    {
        // Intentionally empty: the table itself is dropped by the schema migration's down().
    }
};
