<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the registrar charges for each TLD price column ({register_1y_cents: cents}), in the base currency. */
    public function up(): void
    {
        Schema::table('tlds', function (Blueprint $table) {
            $table->json('costs')->nullable()->after('currency_prices');
        });
    }

    public function down(): void
    {
        Schema::table('tlds', function (Blueprint $table) {
            $table->dropColumn('costs');
        });
    }
};
