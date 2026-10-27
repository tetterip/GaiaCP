<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Coupons (Billing → Coupons): a code that lowers the first payment, or every payment, of what it covers.
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->string('type', 20)->default('percent');
            // A percentage (1-100), or an amount in cents of the base currency.
            $table->unsignedInteger('value');
            $table->boolean('recurring')->default(false);
            $table->json('product_ids')->nullable();
            $table->json('tlds')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('once_per_client')->default(false);
            $table->boolean('new_clients_only')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_cents')->nullable()->after('coupon_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_cents');
        });

        Schema::dropIfExists('coupons');
    }
};
