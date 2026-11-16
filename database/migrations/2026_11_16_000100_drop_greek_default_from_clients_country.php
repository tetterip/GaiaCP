<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clients.country defaulted to GR from when GaiaCP was for one Greek operator: a client made without a country, on an
 * install with no Settings → General → Country, became Greek. No country now stays none; clients keep what they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->char('country', 2)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->char('country', 2)->default('GR')->change();
        });
    }
};
