<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The IP addresses a Proxmox server gives its new VPS, each held by at most one service. */
    public function up(): void
    {
        Schema::create('proxmox_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->unsignedTinyInteger('prefix');
            $table->string('gateway', 45)->nullable();
            $table->foreignId('service_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['server_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxmox_addresses');
    }
};
