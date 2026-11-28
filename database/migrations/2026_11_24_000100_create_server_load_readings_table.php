<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every CPU reading of gaiacp:check-load (App\Monitoring\LoadHistory draws the dashboard's CPU bar from them).
        Schema::create('server_load_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('percent', 5, 1)->nullable();
            $table->decimal('load', 8, 2)->nullable();
            $table->timestamp('checked_at');
            $table->index(['server_id', 'checked_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // Processor cores, for the CPU share when the panel gives only the load average; filled in when it says.
            $table->unsignedSmallInteger('cpu_cores')->nullable()->after('max_accounts');
            // When the bell last rang for a busy CPU; cleared once it calms down, so it rings once per busy spell.
            $table->timestamp('cpu_alerted_at')->nullable()->after('cpu_cores');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['cpu_cores', 'cpu_alerted_at']);
        });

        Schema::dropIfExists('server_load_readings');
    }
};
