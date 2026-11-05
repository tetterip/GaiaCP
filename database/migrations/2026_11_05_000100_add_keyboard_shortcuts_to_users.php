<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Profile → Keyboard shortcuts: an admin can turn off the single-key shortcuts (WCAG 2.1.4, e.g. for speech input). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('keyboard_shortcuts')->default(true)->after('panel_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('keyboard_shortcuts');
        });
    }
};
