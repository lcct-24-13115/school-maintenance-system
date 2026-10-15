<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recorded by Finance AFTER purchase, from official receipts.
        Schema::create('receipts_expenditures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete(); // charged department
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('or_number');                         // official receipt number
            $table->string('supplier')->nullable();
            $table->date('purchase_date');
            $table->decimal('amount', 12, 2);
            $table->string('receipt_path');                      // scanned/photo of receipt
            $table->text('remarks')->nullable();                 // internal; never public
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'purchase_date']);
            $table->unique(['or_number', 'supplier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts_expenditures');
    }
};
