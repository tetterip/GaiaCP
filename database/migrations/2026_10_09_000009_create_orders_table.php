<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin orders: one per client, holding the services ordered together. The services
 * are the order's items; first_payment_cents is what the first payment costs when it
 * differs from the price per cycle (a domain's registration or transfer price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending'); // App\Enums\OrderStatus
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('first_payment_cents')->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('first_payment_cents');
        });

        Schema::dropIfExists('orders');
    }
};
