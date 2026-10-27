<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The email logo (Settings → Mail) becomes the company logo (Settings → Company), shown in the panels too. */
return new class extends Migration
{
    public function up(): void
    {
        $mailLogo = DB::table('settings')->where('key', 'mail.logo')->value('value');

        if ($mailLogo !== null && ! DB::table('settings')->where('key', 'company.logo')->whereNotNull('value')->exists()) {
            DB::table('settings')->updateOrInsert(['key' => 'company.logo'], ['value' => $mailLogo, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('settings')->where('key', 'mail.logo')->delete();
    }

    public function down(): void
    {
        $logo = DB::table('settings')->where('key', 'company.logo')->value('value');

        if ($logo !== null) {
            DB::table('settings')->updateOrInsert(['key' => 'mail.logo'], ['value' => $logo, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
