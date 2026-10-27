<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Off only for servers still on a self-signed certificate.
            $table->boolean('verify_tls')->default(true)->after('api_token');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->text('password')->nullable()->after('username'); // encrypted cast
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('verify_tls'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('password'));
    }
};
