<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no')->nullable()->index();
            $table->string('method', 10);
            $table->string('path');
            $table->text('query');
            $table->longText('body')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('scheme', 5);
            $table->boolean('accepted');
            $table->string('summary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_logs');
    }
};
