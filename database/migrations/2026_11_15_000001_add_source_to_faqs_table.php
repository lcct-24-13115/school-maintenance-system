<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help & FAQ, Phase 4B: where an entry came from.
 *
 *  - starter  made by faq:seed from database/data/faq_starter.php. faq:seed may update it, until an admin edits it.
 *  - custom   written by an admin, or a starter entry an admin has edited. faq:seed never touches it.
 *
 * New rows default to "custom", so nothing an admin adds can ever be overwritten by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->string('source', 10)->default('custom')->after('answer');
        });

        // Rows made by the seeder have no author and were never touched in the FAQ manager (the manager always
        // records created_by / updated_by). Those are the starter rows; everything else stays custom.
        DB::table('faqs')->whereNull('created_by')->whereNull('updated_by')->update(['source' => 'starter']);
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};