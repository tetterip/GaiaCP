<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar details of a domain service, one row per service of type "domain".
 * Billing (price, cycle, due date, payments) stays on the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            // null = the active registrar that sells the TLD (Registrar::forDomain)
            $table->foreignId('registrar_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_type')->default('register'); // App\Enums\DomainRegistrationType
            $table->text('epp_code')->nullable(); // encrypted
            $table->json('nameservers')->nullable();
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Existing domain services: managed at the registrar that sells their TLD.
        $now = now();
        DB::table('services')->where('type', 'domain')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => DB::table('domains')->insert([
                'service_id' => $id,
                'registration_type' => 'register',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
