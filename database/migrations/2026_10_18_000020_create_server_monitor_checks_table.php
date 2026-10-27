<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every status read by gaiacp:check-monitors (App\Monitoring\UptimeHistory draws the uptime bar from them).
        Schema::create('server_monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('checked_at');
            $table->index(['server_id', 'checked_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // The monitoring service's own uptime ratios (window => 0-1, e.g. {"1d": 0.9998}), when it gives them.
            $table->json('monitor_uptime')->nullable()->after('monitor_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('monitor_uptime');
        });

        Schema::dropIfExists('server_monitor_checks');
    }
};
