<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies (Settings → Currencies): the base currency (gaiacp.currency, EUR) at rate 1 plus any others, each with
 * its display format and rate to the base. Every client gets a currency (the base one for those already here);
 * products and TLDs keep their prices in other currencies in `currency_prices` (none = the base price at the rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('prefix', 10)->nullable();
            $table->string('suffix', 10)->nullable();
            $table->string('format', 20)->default('locale');
            $table->decimal('rate', 15, 5)->default(1);
            $table->timestamps();
        });

        $base = strtoupper((string) (config('gaiacp.currency') ?: 'EUR'));
        DB::table('currencies')->insert([
            'code' => $base,
            'prefix' => ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$base] ?? null,
            'suffix' => $base,
            'format' => 'locale',
            'rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('clients', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('locale');
        });
        DB::table('clients')->update(['currency' => $base]);

        Schema::table('products', function (Blueprint $table) {
            $table->json('currency_prices')->nullable()->after('prices');
        });

        Schema::table('tlds', function (Blueprint $table) {
            $table->json('currency_prices')->nullable()->after('redemption_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('tlds', fn (Blueprint $table) => $table->dropColumn('currency_prices'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('currency_prices'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::dropIfExists('currencies');
    }
};
