<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who paid: the service's client when the payment was recorded. A service moved to another client
 * (TransferService) takes its future payments along; the earlier ones stay with the client who made them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });

        DB::table('payments')->update([
            'client_id' => DB::table('services')->select('client_id')->whereColumn('services.id', 'payments.service_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
