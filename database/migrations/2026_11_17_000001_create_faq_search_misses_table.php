<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help & FAQ, Phase 5B: searches on the Help page that found no answer.
 *
 * One row per distinct search term (lowercased). Only the words are kept, with how many times they were searched and
 * when last. NO user, IP, session or any other personal data is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_search_misses', function (Blueprint $table) {
            $table->id();
            $table->string('term', 100)->unique();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['count', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_search_misses');
    }
};
