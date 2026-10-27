<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The setup guide (Settings → General → Setup guide) is for new installs: one that already has clients starts with
 * the guide hidden from the dashboard. The page itself stays open to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('clients')->exists() || DB::table('settings')->where('key', 'setup_guide')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'setup_guide',
            'value' => json_encode(['marked' => [], 'hidden' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'setup_guide')->delete();
    }
};
