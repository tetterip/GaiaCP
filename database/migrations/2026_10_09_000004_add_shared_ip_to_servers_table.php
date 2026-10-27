<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Plesk: IP address new subscriptions are created on; empty = the server's first shared IP.
            $table->string('shared_ip', 45)->nullable()->after('port');
            // Plesk can authenticate with a secret key alone.
            $table->string('username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('shared_ip');
            $table->string('username')->nullable(false)->change();
        });
    }
};
