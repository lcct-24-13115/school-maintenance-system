<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-department Phase 1.
 *
 *  - departments: every department can now RECEIVE tickets (is_service_department), can be switched off (is_active),
 *    has a head (head_user_id) and a description.
 *  - requests (tickets): assigned_department_id = the department that RECEIVES the ticket.
 *    The existing department_id stays the REQUESTER's department.
 *
 * Existing tickets all belonged to the Maintenance Office, so they are back-filled to it.
 * The Maintenance Office row is created here only when there are tickets to back-fill
 * (a fresh database gets it from DepartmentSeeder, code "MNT").
 */
return new class extends Migration
{
    private const MAINTENANCE_CODE = 'MNT';

    private const MAINTENANCE_NAME = 'Maintenance Office';

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('code');
            $table->boolean('is_service_department')->default(true)->after('is_active');
            $table->foreignId('head_user_id')->nullable()->after('is_service_department')
                ->constrained('users')->nullOnDelete();
            $table->text('description')->nullable()->after('head_user_id');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('assigned_department_id')->nullable()->after('department_id')
                ->constrained('departments')->nullOnDelete();
        });

        // Back-fill: every existing ticket goes to the Maintenance Office.
        if (DB::table('requests')->exists()) {
            $id = DB::table('departments')->where('code', self::MAINTENANCE_CODE)->value('id')
                ?? DB::table('departments')->where('name', self::MAINTENANCE_NAME)->value('id');

            if (! $id) {
                $id = DB::table('departments')->insertGetId([
                    'name' => self::MAINTENANCE_NAME,
                    'code' => self::MAINTENANCE_CODE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('requests')->whereNull('assigned_department_id')->update(['assigned_department_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_department_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'is_service_department', 'description']);
        });
    }
};
