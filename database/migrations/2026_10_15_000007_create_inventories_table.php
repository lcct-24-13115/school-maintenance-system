<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('property_tag')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('custodian_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete(); // origin, if acquired via request
            $table->enum('condition', ['good', 'fair', 'needs_repair', 'unserviceable', 'disposed'])->default('good');
            $table->string('location')->nullable();
            $table->date('acquired_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Year-end physical inventory checks
        Schema::create('inventory_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('check_year');
            $table->enum('result', ['found', 'missing', 'damaged', 'transferred']);
            $table->text('remarks')->nullable();
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['inventory_id', 'check_year']);
        });

        // Personnel clearance
        Schema::create('clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // personnel being cleared
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'cleared', 'with_accountability'])->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearances');
        Schema::dropIfExists('inventory_checks');
        Schema::dropIfExists('inventories');
    }
};
