<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->timestamps();
        });

        // Annual maintenance budget per department (drives budget-limit alerts)
        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('allocated_amount', 12, 2)->default(0);
            $table->unsignedTinyInteger('alert_threshold_percent')->default(80);
            $table->timestamps();

            $table->unique(['department_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_budgets');
        Schema::dropIfExists('departments');
    }
};
