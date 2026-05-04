<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipi_server_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            $table->float('load_1m')->nullable();
            $table->float('load_5m')->nullable();
            $table->float('load_15m')->nullable();
            $table->float('cpu_usage_percent')->nullable();
            $table->unsignedBigInteger('memory_total_mb')->nullable();
            $table->unsignedBigInteger('memory_used_mb')->nullable();
            $table->float('memory_usage_percent')->nullable();
            $table->unsignedBigInteger('swap_total_mb')->nullable();
            $table->unsignedBigInteger('swap_used_mb')->nullable();
            $table->float('disk_root_usage_percent')->nullable();
            $table->json('disks')->nullable();
            $table->json('services')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_server_metrics');
    }
};
