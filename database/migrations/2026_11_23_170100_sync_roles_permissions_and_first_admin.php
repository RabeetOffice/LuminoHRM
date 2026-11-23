<?php

use App\Support\AccessSync;
use Illuminate\Database\Migrations\Migration;

// Data migration: production runs `migrate --force` but never seeds. Idempotent.
// To change permissions later: edit config/hrm.php and add a new migration like this one.
return new class extends Migration
{
    public function up(): void
    {
        (new AccessSync)->run();
    }

    public function down(): void
    {
        // Intentionally empty: roles, permissions and users are never removed on rollback.
    }
};
