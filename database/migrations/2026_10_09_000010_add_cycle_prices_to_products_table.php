<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products get a price and setup fee per billing cycle (like WHMCS's pricing tab),
 * stored as JSON {cycle: {enabled, price_cents, setup_cents}}; billing_cycle stays as
 * the default cycle. "active" becomes "retired" (its opposite), and "hidden" keeps a
 * product out of the new order picker only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('prices')->nullable()->after('description');
            $table->boolean('hidden')->default(false)->after('package');
            $table->boolean('retired')->default(false)->after('hidden');
        });

        DB::table('products')->orderBy('id')->get()->each(fn ($product) => DB::table('products')->where('id', $product->id)->update([
            'prices' => json_encode([$product->billing_cycle => ['enabled' => true, 'price_cents' => (int) $product->price_cents, 'setup_cents' => 0]]),
            'retired' => ! $product->active,
        ]));

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price_cents', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('active')->default(true);
        });

        DB::table('products')->orderBy('id')->get()->each(fn ($product) => DB::table('products')->where('id', $product->id)->update([
            'price_cents' => (int) (json_decode((string) $product->prices, true)[$product->billing_cycle]['price_cents'] ?? 0),
            'active' => ! $product->retired,
        ]));

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['prices', 'hidden', 'retired']);
        });
    }
};
