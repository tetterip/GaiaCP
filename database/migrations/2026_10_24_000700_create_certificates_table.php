<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The provider side of an SSL certificate service (App\Models\Certificate), like `domains` for a domain.
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable(); // App\Certificates\CertificateModule::key()
            $table->string('product_code')->nullable(); // the provider's product, from the service's package
            $table->string('remote_id')->nullable(); // the provider's order id
            $table->string('status')->default('not_ordered'); // App\Enums\CertificateStatus
            $table->boolean('wildcard')->default(false);
            $table->string('dcv_method')->nullable(); // dns, http, https or email
            $table->string('approver_email')->nullable();
            $table->json('validation')->nullable(); // what the client puts where, from the provider
            $table->text('csr')->nullable();
            $table->text('private_key')->nullable(); // encrypted; empty when the client sent their own CSR
            $table->text('certificate')->nullable();
            $table->text('ca_bundle')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('expires_at')->nullable(); // the certificate's own end
            $table->date('ends_at')->nullable(); // the order's (subscription's) end at the provider
            $table->json('previous_orders')->nullable(); // remote ids of earlier orders (renewals)
            $table->string('error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
