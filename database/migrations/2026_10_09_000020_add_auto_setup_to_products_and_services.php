<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a pending service is set up (App\Enums\AutoSetup). The product holds the setting; the
 * service keeps its own copy like price and package. "accept" (Complete order) is today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('auto_setup')->default('accept')->after('package');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('auto_setup')->default('accept')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('auto_setup'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('auto_setup'));
    }
};
