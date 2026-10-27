<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // [{host, ip}]: the private nameservers registered from GaiaCP (registrars can't list them)
            $table->json('child_nameservers')->nullable()->after('nameservers');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('child_nameservers');
        });
    }
};
