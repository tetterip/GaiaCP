<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The client's own hosting account the certificate was validated or installed on (App\Certificates\CertificateHosting).
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('hosting_service_id')->nullable()->after('service_id')->constrained('services')->nullOnDelete();
            $table->timestamp('validation_placed_at')->nullable()->after('validation');
            $table->timestamp('installed_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hosting_service_id');
            $table->dropColumn(['validation_placed_at', 'installed_at']);
        });
    }
};
