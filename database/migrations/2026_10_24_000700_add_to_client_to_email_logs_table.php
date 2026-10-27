<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the emails sent to the client themselves, which the client area's Email history lists, apart from the
 * admins' emails about a client (new client, new order…). Earlier rows count when they went to the client's address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->boolean('to_client')->default(false)->after('client_id');
            $table->index(['client_id', 'to_client']);
        });

        DB::table('clients')
            ->whereIn('id', DB::table('email_logs')->whereNotNull('client_id')->select('client_id'))
            ->whereNotNull('email')
            ->select(['id', 'email'])
            ->orderBy('id')
            ->each(fn (object $client) => DB::table('email_logs')
                ->where('client_id', $client->id)
                ->where('status', 'sent')
                ->where('to', 'like', '%'.$client->email.'%')
                ->update(['to_client' => true]));
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'to_client']);
            $table->dropColumn('to_client');
        });
    }
};
