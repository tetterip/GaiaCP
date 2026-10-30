<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the user first saw the welcome tour of each panel (App\Support\WelcomeTour), so it opens by itself only once.
 * Users from before the tour have used the panels already: they count as having seen it (the menu still opens it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('admin_tour_seen_at')->nullable()->after('two_factor_reminder_dismissed_at');
            $table->timestamp('client_tour_seen_at')->nullable()->after('admin_tour_seen_at');
        });

        DB::table('users')->update(['admin_tour_seen_at' => now(), 'client_tour_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['admin_tour_seen_at', 'client_tour_seen_at']);
        });
    }
};
