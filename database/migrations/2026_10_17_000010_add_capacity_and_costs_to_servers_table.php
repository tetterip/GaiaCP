<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // What the server costs a month, in the base currency (Income forecast and Server revenue show profit).
            $table->unsignedInteger('monthly_cost_cents')->nullable()->after('shared_ip');
            // Accounts it should hold; null = no limit (the Servers list shows the count without a bar).
            $table->unsignedInteger('max_accounts')->nullable()->after('monthly_cost_cents');
            $table->string('ip_address', 45)->nullable()->after('max_accounts');
            $table->string('status_url')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['monthly_cost_cents', 'max_accounts', 'ip_address', 'status_url']);
        });
    }
};
