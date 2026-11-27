<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.must_change_password: true while the account still has a password somebody else chose (the first Admin, made
 * from HRM_ADMIN_PASSWORD in an env seed). Every page then leads to /first-login/password until the person picks their own.
 *
 * Dated just before the access-sync data migration on purpose: on a fresh install the column exists by the time the
 * first Admin is created there. An installed system simply runs it as one more pending change (additive, default false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
