<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Support tickets (App\Actions\Tickets): departments, tickets, their messages and files, and the admins' canned replies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            // Offered to clients when they open a ticket; an admin can still move a ticket into a hidden one
            $table->boolean('client_visible')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('status')->default('open'); // App\Enums\TicketStatus
            $table->string('priority')->default('medium'); // App\Enums\TicketPriority
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_reply_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // The admin or the client's user who wrote it; null once that user is deleted
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_client')->default(false);
            // An admins' note, never shown or emailed to the client
            $table->boolean('internal')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_message_id')->constrained()->cascadeOnDelete();
            // On the private "local" disk, downloaded only through TicketAttachmentController
            $table->string('path');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->timestamps();
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_departments');
    }
};
