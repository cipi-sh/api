<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipi_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id')->index();
            $table->string('platform', 16);
            $table->string('push_token', 512);
            $table->string('device_name')->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->json('notification_preferences')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['token_id', 'push_token'], 'cipi_devices_token_push_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cipi_devices');
    }
};
