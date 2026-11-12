<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I am a ..." on registration. All nullable, so every existing account keeps working untouched.
 *   requester_type  key from config/registration.php (STUDENT, ALUMNI, ...)
 *   affiliation     program / organization / department, depending on the type
 *   reg_purpose     why the person asked for an account (shown to the admin who approves)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('requester_type', 40)->nullable();
            $table->string('affiliation', 190)->nullable();
            $table->string('reg_purpose', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['requester_type', 'affiliation', 'reg_purpose']);
        });
    }
};
