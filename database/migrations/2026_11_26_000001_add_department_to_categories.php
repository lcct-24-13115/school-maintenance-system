<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-department Phase 2: every category belongs to ONE department.
 *
 * The old categories (Plumbing, Electrical, Air Conditioning ...) all belong to the Maintenance Office.
 * The name must now be unique per department + type (before: per type), because two departments may
 * both have a category such as "Other".
 */
return new class extends Migration
{
    private const MAINTENANCE_CODE = 'MNT';

    private const MAINTENANCE_NAME = 'Maintenance Office';

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('type')
                ->constrained('departments')->nullOnDelete();
            $table->dropUnique(['name', 'type']);
            $table->unique(['department_id', 'name', 'type']);
        });

        // Back-fill: existing categories go to the Maintenance Office (created here only if there is something to back-fill).
        if (DB::table('categories')->exists()) {
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

            DB::table('categories')->whereNull('department_id')->update(['department_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['department_id', 'name', 'type']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        // Fails if two departments now share a name + type; remove or rename those categories first.
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['name', 'type']);
        });
    }
};
