<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings → General → Region is new and a new install starts in UTC with no country and no tax rule. An install
 * that already has admins keeps what it had until now: Europe/Athens (or its APP_TIMEZONE), d/m/Y, Greece and
 * the 17% tax rule, each stored only when it isn't already.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('users')->exists()) {
            return;
        }

        $timezone = (string) config('app.timezone');
        $pinned = [
            'region.timezone' => $timezone !== '' && $timezone !== 'UTC' ? $timezone : 'Europe/Athens',
            'region.date_format' => 'd/m/Y',
            'region.country' => 'GR',
            'tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 17, 'country' => null]],
        ];

        foreach ($pinned as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // The values stay: they are what the install used before
    }
};
