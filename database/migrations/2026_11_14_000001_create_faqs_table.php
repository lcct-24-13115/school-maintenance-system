<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help & FAQ, Phase 1: the questions and answers of the Help page.
 *
 *  - audience   ["all"] or a list of role slugs (["custodian", "maintenance_admin"]). Empty / null counts as "all".
 *  - is_public  may be read BEFORE signing in. Only meaningful when the audience is "all" (the model enforces it).
 *  - answer     plain text with a tiny safe format (see App\Support\FaqText): "- " bullets, "1. " steps, **bold**, [label](/path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 60);
            $table->json('audience')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('question', 255);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'topic', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
