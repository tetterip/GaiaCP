<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The monitoring plugin (App\Monitoring\MonitoringModule key) and its monitor for this server.
            $table->string('monitor_module')->nullable()->after('status_url');
            $table->string('monitor_ref')->nullable()->after('monitor_module');
            // Last status read (App\Monitoring\MonitorStatus), since when, and when it was last read.
            $table->string('monitor_status', 20)->nullable()->after('monitor_ref');
            $table->timestamp('monitor_status_since')->nullable()->after('monitor_status');
            $table->timestamp('monitor_checked_at')->nullable()->after('monitor_status_since');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['monitor_module', 'monitor_ref', 'monitor_status', 'monitor_status_since', 'monitor_checked_at']);
        });
    }
};
