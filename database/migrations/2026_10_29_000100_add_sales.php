<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Sale prices (App\Models\Concerns\HasSale): a product's sale price per cycle (products.prices {cycle: {sale_cents}})
    // and a TLD's for registering and transferring (tlds.sale_prices {register_1y_cents: cents}), from sale_starts_on
    // to sale_ends_on. A coupon lowers an item on sale only with coupons.on_sale.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('sale_starts_on')->nullable();
            $table->date('sale_ends_on')->nullable();
        });

        Schema::table('tlds', function (Blueprint $table) {
            $table->json('sale_prices')->nullable();
            $table->date('sale_starts_on')->nullable();
            $table->date('sale_ends_on')->nullable();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('on_sale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['sale_starts_on', 'sale_ends_on']));
        Schema::table('tlds', fn (Blueprint $table) => $table->dropColumn(['sale_prices', 'sale_starts_on', 'sale_ends_on']));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('on_sale'));
    }
};
