<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help & FAQ, Phase 5A: "Was this helpful?" votes.
 *
 *  - One vote per signed-in user per entry (unique faq_id + user_id). Voting again changes the vote.
 *  - A guest (not signed in) has no user_id. A guest is told apart by session_hash: a hash of a random token kept in
 *    their session, so the token itself is never stored. One vote per guest session per entry.
 *  - ip_hash is a keyed hash of the IP address, kept for guests only (to spot abuse). The raw IP is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_id')->constrained('faqs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_hash', 64)->nullable();
            $table->boolean('helpful');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['faq_id', 'user_id']);
            $table->unique(['faq_id', 'session_hash']);
            $table->index(['faq_id', 'helpful']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_feedback');
    }
};
