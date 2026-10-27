<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client credit: a balance per client (in the client's currency) and the ledger that explains it. Funds come in
 * through an "Add funds" payment notice (invoices.credit, one invoice_items.credit line) or an admin's adjustment,
 * and go out by paying payment notice lines (payments.method "credit").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->integer('credit_cents')->default(0);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // App\Enums\CreditType
            $table->integer('amount_cents'); // + in, - out
            $table->integer('balance_cents'); // the balance after it
            $table->char('currency', 3);
            $table->string('description');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method')->nullable(); // how a deposit was paid
            $table->string('reference')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = the client or automation
            $table->timestamp('created_at');

            $table->index(['client_id', 'id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('credit')->default(false); // an "Add funds" notice
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('credit')->default(false); // the "Add funds" line: no service, pays into the balance
            $table->foreignId('credit_transaction_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_transaction_id');
            $table->dropColumn('credit');
        });

        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('credit'));
        Schema::dropIfExists('credit_transactions');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('credit_cents'));
    }
};
