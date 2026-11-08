<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A refund is a payment of minus the amount (App\Actions\RefundPayment), naming the payment it gave back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('refund_of')->nullable()->after('client_id')->index()->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['refund_of']);
            $table->dropIndex(['refund_of']);
            $table->dropColumn('refund_of');
        });
    }
};
