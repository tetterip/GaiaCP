<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar accounts (Settings → Registrars) and the TLDs each one sells,
 * with selling prices for 1 and 2 years. A null price means that term is
 * not offered for that TLD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('module'); // a plugin's RegistrarModule key (easygr)
            $table->text('credentials')->nullable(); // encrypted array
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('registrar_tlds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registrar_id')->constrained()->cascadeOnDelete();
            $table->string('tld', 63); // with the leading dot, lowercase: ".com.gr"
            $table->boolean('active')->default(true);
            $table->boolean('epp_required')->default(true);
            foreach (['register', 'transfer', 'renew'] as $kind) {
                $table->unsignedInteger("{$kind}_1y_cents")->nullable();
                $table->unsignedInteger("{$kind}_2y_cents")->nullable();
            }
            $table->timestamps();

            $table->unique(['registrar_id', 'tld']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrar_tlds');
        Schema::dropIfExists('registrars');
    }
};
