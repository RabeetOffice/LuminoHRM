<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit indexes on the two KPI target columns. MariaDB already builds one for each foreign key; naming them here keeps the
 * schema the same everywhere (SQLite builds none) and makes the lookups of the KPI list, the designation delete check and the
 * review snapshot independent of how the foreign keys were created. Additive: the create migration has already run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpis', function (Blueprint $table) {
            $table->index('designation_id', 'kpis_designation_id_index');
            $table->index('employee_id', 'kpis_employee_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('kpis', function (Blueprint $table) {
            $table->dropIndex('kpis_designation_id_index');
            $table->dropIndex('kpis_employee_id_index');
        });
    }
};
