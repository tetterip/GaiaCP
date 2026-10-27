<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which GaiaCP record each imported WHMCS row became (Plugins\WhmcsImport\WhmcsImport), so the import can
     * run again without creating anything twice. entity: client, server, product, hosting, domain, invoice.
     */
    public function up(): void
    {
        Schema::create('whmcs_links', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20);
            $table->unsignedBigInteger('whmcs_id');
            $table->unsignedBigInteger('local_id');
            $table->timestamps();
            $table->unique(['entity', 'whmcs_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_links');
    }
};
