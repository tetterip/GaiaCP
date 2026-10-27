<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why a notice charges no tax although a rule applied (Plugin::taxExemption(), e.g. a reverse charge). */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_note', 500)->nullable()->after('tax_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_note');
        });
    }
};
