<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->date('override_suspend_until')->nullable()->after('suspension_reason');
            $table->boolean('auto_terminate')->default(false)->after('override_suspend_until');
            $table->string('auto_terminate_reason')->nullable()->after('auto_terminate');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['override_suspend_until', 'auto_terminate', 'auto_terminate_reason']);
        });
    }
};
