<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (additive): the reply thread of a ticket.
 *  - ticket_replies          one message (text and/or files) from the requester or staff
 *  - request_attachments     new nullable reply_id: the files that belong to ONE reply.
 *                            Existing rows keep reply_id = NULL and nothing about them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body')->nullable();   // may be empty when the reply is only files
            $table->timestamps();

            $table->index(['request_id', 'id'], 'ticket_replies_thread_idx');
        });

        Schema::table('request_attachments', function (Blueprint $table) {
            $table->foreignId('reply_id')->nullable()->after('request_id')->constrained('ticket_replies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_id');
        });

        Schema::dropIfExists('ticket_replies');
    }
};
