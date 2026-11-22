<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GaiaCP version whose What's new window an admin has seen (App\Support\WhatsNew). Empty for the admins who were
 * there before it, so they see it once after this update; a user added later starts at the version of that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whats_new_version', 32)->nullable()->after('client_tour_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whats_new_version');
        });
    }
};
