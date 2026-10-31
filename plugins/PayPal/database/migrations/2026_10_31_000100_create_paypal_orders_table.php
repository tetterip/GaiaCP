<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every PayPal order opened for a payment notice (Plugins\PayPal): what it asked for and what came of it, so a
     * payment is taken and recorded once however often PayPal reports it. status: open, processing, pending (taken,
     * still clearing at PayPal), paid, cancelled (not taken: the notice changed first), failed, unmatched (taken at
     * PayPal but not recorded here; `error` says why).
     */
    public function up(): void
    {
        Schema::create('paypal_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('order_id')->unique();
            $table->boolean('live')->default(false);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20)->default('open')->index();
            $table->string('capture_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_orders');
    }
};
