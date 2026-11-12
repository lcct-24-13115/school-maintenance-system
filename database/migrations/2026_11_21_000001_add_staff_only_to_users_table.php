<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Staff only" for a maintenance account: may take an unassigned ticket and transfer their OWN ticket
 * (a reason is required), but may not assign tickets to other people. false (the default) = the Head of
 * Maintenance, who assigns and reassigns anyone - so every existing account keeps exactly the powers it has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('staff_only')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_only');
        });
    }
};
