<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail log: one row per e-mail the system tries to send, with the real delivery result.
 * status: sending (handed to the mailer, no answer yet) | delivered | failed.
 * body_html is kept ONLY for ticket e-mails (they hold no secrets) so a failed one can be sent again.
 * Password-reset and verification e-mails are logged WITHOUT their body (their link is a secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('sending')->index();
            $table->string('type', 60)->nullable()->index();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 255);
            $table->string('ticket_no', 40)->nullable()->index();
            $table->string('mailer', 30)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->text('error')->nullable();
            $table->longText('body_html')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
