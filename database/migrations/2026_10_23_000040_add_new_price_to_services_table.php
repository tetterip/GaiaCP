<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled price change (App\Actions\ChangePrice): the service's price becomes new_price_cents from the first payment
 * notice for a due date on or after new_price_from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('new_price_cents')->nullable()->after('first_payment_cents');
            $table->date('new_price_from')->nullable()->after('new_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['new_price_cents', 'new_price_from']);
        });
    }
};
