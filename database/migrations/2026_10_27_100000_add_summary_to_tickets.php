<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The one-line summary an admin kept from the AI assistant (ticket → Summarise), shown under the subject in the list.
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('summary', 300)->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
