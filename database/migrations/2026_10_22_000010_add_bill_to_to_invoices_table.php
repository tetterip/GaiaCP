<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The client's name, address and VAT details as they were when the notice was issued (Invoice::BILL_TO), so a later
 * change to the client does not rewrite old notices. Notices issued before this get the client's details of today:
 * the older ones were never kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('bill_to')->nullable()->after('client_id');
        });

        $fields = ['company', 'first_name', 'last_name', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'vat_number', 'tax_office'];

        DB::table('clients')->select(['id', ...$fields])->orderBy('id')->each(function (object $client) use ($fields) {
            DB::table('invoices')->where('client_id', $client->id)->whereNull('bill_to')->update([
                'bill_to' => json_encode(array_combine($fields, array_map(fn (string $field) => $client->{$field}, $fields))),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('bill_to');
        });
    }
};
