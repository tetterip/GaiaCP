<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Leads (Clients → Leads): prospective clients with their notes, until they become a client or are lost. */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('source', 100)->nullable(); // one of Settings → Sales → Lead sources, or what it was then
            $table->string('status', 20)->default('new')->index(); // LeadStatus
            $table->string('lost_reason')->nullable();
            $table->text('notes')->nullable(); // what they are looking for
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_contact_on')->nullable()->index();
            $table->date('follow_up_sent_on')->nullable(); // the next_contact_on the bell already rang for
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // the client it became
            $table->timestamp('closed_at')->nullable(); // won or lost
            $table->timestamps();
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('leads');
    }
};
