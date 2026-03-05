<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipi_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->json('params')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->text('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_jobs');
    }
};
