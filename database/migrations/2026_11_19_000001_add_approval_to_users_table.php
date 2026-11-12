<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account approval for self-registration.
 * approval_status: approved (default, so EVERY existing account keeps working) | pending | rejected.
 * decided_by / decided_at / rejection_reason record who decided, when, and why (a rejection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status', 10)->default('approved')->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'decided_by', 'decided_at', 'rejection_reason']);
        });
    }
};
