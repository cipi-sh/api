<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cipi_jobs')) {
            return;
        }

        Schema::table('cipi_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('cipi_jobs', 'app')) {
                $table->string('app')->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('cipi_jobs', 'log_path')) {
                $table->string('log_path', 1024)->nullable()->after('output');
            }
            if (! Schema::hasColumn('cipi_jobs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('exit_code');
            }
            if (! Schema::hasColumn('cipi_jobs', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('cipi_jobs', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable()->after('finished_at');
            }
            if (! Schema::hasColumn('cipi_jobs', 'triggered_by')) {
                $table->string('triggered_by', 64)->nullable()->after('duration_seconds');
            }
            if (! Schema::hasColumn('cipi_jobs', 'token_id')) {
                $table->unsignedBigInteger('token_id')->nullable()->after('triggered_by')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cipi_jobs')) {
            return;
        }

        Schema::table('cipi_jobs', function (Blueprint $table) {
            foreach (['app', 'log_path', 'started_at', 'finished_at', 'duration_seconds', 'triggered_by', 'token_id'] as $col) {
                if (Schema::hasColumn('cipi_jobs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
