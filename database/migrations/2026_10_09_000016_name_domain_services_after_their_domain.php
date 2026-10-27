<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Domains are called by their name now; the generic label they got by default goes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')
            ->where('type', 'domain')
            ->where('label', 'Domain registration')
            ->whereNotNull('domain')
            ->update(['label' => DB::raw('domain')]);
    }

    public function down(): void {}
};
