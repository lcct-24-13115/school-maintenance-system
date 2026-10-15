<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per signature line on any of the 3 forms (polymorphic).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');                                   // facility / requisition / repair
            $table->string('step', 30);                                     // requested, endorsed, noted, recommended, treasurer, president
            $table->unsignedTinyInteger('step_order');
            $table->string('label');                                        // "Noted by (Property Custodian / Director...)"
            $table->string('role_slug', 30);                                // who is allowed to sign this line
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who actually signed
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('signed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['approvable_type', 'approvable_id', 'step'], 'form_approvals_unique_step');
            $table->index(['role_slug', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_approvals');
    }
};
