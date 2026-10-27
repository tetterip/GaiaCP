<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `version` = the version installed here (App\Plugins\PluginManager::recordVersions()); these say since when and from what.
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('previous_version')->nullable()->after('version');
            $table->timestamp('version_changed_at')->nullable()->after('previous_version');
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropColumn(['previous_version', 'version_changed_at']);
        });
    }
};
