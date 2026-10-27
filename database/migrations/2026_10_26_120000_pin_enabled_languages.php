<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GaiaCP now ships German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish. Settings →
 * Languages offers every language found unless it was saved, so an install that already has admins keeps the
 * languages it offered until now (English, Greek and any uploaded one) and switches the new ones on there itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('users')->exists() || DB::table('settings')->where('key', 'languages.enabled')->exists()) {
            return;
        }

        $uploaded = array_map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME), glob(storage_path('app/private/lang/*.json')) ?: []);
        $enabled = array_values(array_unique(['en', 'el', ...$uploaded]));

        DB::table('settings')->insert(['key' => 'languages.enabled', 'value' => json_encode($enabled), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The value stays: it is what the install offered before
    }
};
