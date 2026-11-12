<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Subcategories: a category (Plumbing, Air Conditioning ...) can have optional sub-types
// (Leaking faucet, Not cooling ...). A ticket keeps its category and may also carry ONE subcategory of it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_hidden')->default(false);   // hidden = not offered on new tickets; old tickets keep it
            $table->timestamps();

            $table->unique(['category_id', 'name']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('subcategory_id')->nullable()->after('category_id')
                ->constrained('subcategories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcategory_id');
        });

        Schema::dropIfExists('subcategories');
    }
};
