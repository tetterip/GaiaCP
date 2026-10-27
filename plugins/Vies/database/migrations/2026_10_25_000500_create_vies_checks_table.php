<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every VIES check made (Plugins\Vies\ViesCheck), by VAT number, so a client's latest one decides the reverse charge. */
    public function up(): void
    {
        Schema::create('vies_checks', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2); // VIES code: EL for Greece
            $table->string('vat_number', 20); // without the country prefix
            $table->boolean('valid');
            $table->string('name', 500)->nullable();
            $table->string('address', 1000)->nullable();
            $table->string('request_identifier', 100)->nullable(); // the consultation number
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['country_code', 'vat_number', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vies_checks');
    }
};
