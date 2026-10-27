<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotes (Billing → Quotes): an offer to a lead or a client that becomes an order when accepted.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique(); // QT-2026-0001
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft')->index(); // QuoteStatus
            $table->string('currency', 3);
            $table->string('locale', 10)->nullable(); // the language of its page and email
            $table->json('items'); // the order's items as New order makes them (PlaceOrder), prices in cents
            $table->json('extras')->nullable(); // one-off lines, ordered as one-time other services: [{description, amount_cents}]
            $table->text('intro')->nullable();
            $table->text('footer')->nullable();
            $table->date('valid_until');
            $table->string('token', 64)->unique(); // its page: /quote/{token}
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable(); // for the admins only
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who wrote it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
