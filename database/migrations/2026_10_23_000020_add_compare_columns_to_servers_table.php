<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servers → Compare with the server: accounts the admin marked "Not billed on purpose" (username => note) and what the
 * nightly comparison (gaiacp:compare-servers) last told the admins about, so it only tells them what is new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('ignored_accounts')->nullable();
            $table->json('compare_alerted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['ignored_accounts', 'compare_alerted']);
        });
    }
};
