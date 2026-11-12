<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-department Phase 3: the generic ticket form (/tickets/create).
 *
 *  - title            short summary line (the FO-08 repair form has none; it keeps using `location`)
 *  - contact_*        how the receiving department can reach the requester
 *  - location         now optional: a generic ticket (e.g. a transcript request) has no building / room
 *  - index            makes the "unassigned queue" of a department cheap to list
 *
 * Nothing is deleted or rewritten: old tickets keep their location and get NULL title / contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('title', 150)->nullable()->after('subcategory_id');
            $table->string('contact_name', 120)->nullable()->after('reason');
            $table->string('contact_email', 150)->nullable()->after('contact_name');
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            $table->string('location')->nullable()->change();
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->index(['assigned_department_id', 'assigned_to', 'status'], 'requests_dept_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_dept_queue_index');
        });

        // Tickets filed without a location would break the NOT NULL column: give them an empty one first.
        \Illuminate\Support\Facades\DB::table('requests')->whereNull('location')->update(['location' => '']);

        Schema::table('requests', function (Blueprint $table) {
            $table->string('location')->nullable(false)->change();
            $table->dropColumn(['title', 'contact_name', 'contact_email', 'contact_phone']);
        });
    }
};
