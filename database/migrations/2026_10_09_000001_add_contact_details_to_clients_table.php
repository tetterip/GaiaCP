<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings clients in line with the details a domain registration needs
 * (registrant contact): split name, structured address, country, plus the
 * Greek invoicing fields (profession, tax office) and per-client email
 * preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_name')->default('')->after('id');
            $table->string('last_name')->default('')->after('first_name');
            $table->string('address1')->nullable()->after('vat_number');
            $table->string('address2')->nullable()->after('address1');
            $table->string('city')->nullable()->after('address2');
            $table->string('state')->nullable()->after('city');
            $table->string('postcode', 20)->nullable()->after('state');
            $table->char('country', 2)->default('GR')->after('postcode');
            $table->string('profession')->nullable()->after('vat_number');
            $table->string('tax_office')->nullable()->after('profession');
            $table->string('status')->default('active')->after('tax_office');
            $table->string('default_payment_method')->nullable()->after('status');
            $table->boolean('renewal_reminders')->default(true)->after('default_payment_method');
            $table->boolean('overdue_notices')->default(true)->after('renewal_reminders');
        });

        // Existing rows: "First Last" -> first_name / last_name, old address -> address1.
        foreach (DB::table('clients')->get(['id', 'name', 'address']) as $row) {
            [$first, $last] = array_pad(preg_split('/\s+/', trim($row->name), 2), 2, '');
            DB::table('clients')->where('id', $row->id)->update([
                'first_name' => $first,
                'last_name' => $last,
                'address1' => $row->address,
            ]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['name', 'address']);
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('name')->default('');
            $table->text('address')->nullable();
        });

        foreach (DB::table('clients')->get() as $row) {
            DB::table('clients')->where('id', $row->id)->update([
                'name' => trim("{$row->first_name} {$row->last_name}"),
                'address' => $row->address1,
            ]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['last_name', 'first_name']);
            $table->dropColumn([
                'first_name', 'last_name', 'address1', 'address2', 'city', 'state', 'postcode', 'country',
                'profession', 'tax_office', 'status', 'default_payment_method', 'renewal_reminders', 'overdue_notices',
            ]);
        });
    }
};
