<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase 5: Categories admin. sort_order = order in the lists, is_hidden = not offered on new tickets,
// is_locked = protected (cannot be deleted, renamed or retyped). The old is_active column is left alone
// (nothing reads it any more); is_hidden replaces it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('description');
            $table->boolean('is_hidden')->default(false)->after('sort_order');
            $table->boolean('is_locked')->default(false)->after('is_hidden');
        });

        // Categories that were switched off before stay off.
        DB::table('categories')->where('is_active', false)->update(['is_hidden' => true]);

        // Start the order inside each type: alphabetical.
        foreach (DB::table('categories')->distinct()->pluck('type') as $type) {
            $n = 0;
            foreach (DB::table('categories')->where('type', $type)->orderBy('name')->pluck('id') as $id) {
                DB::table('categories')->where('id', $id)->update(['sort_order' => ++$n]);
            }
        }

        // An existing database gets its locked "Other" category (one per type) right away.
        // (An empty database gets them from CategorySeeder, or the first time a category is deleted.)
        foreach (DB::table('categories')->distinct()->pluck('type') as $type) {
            $exists = DB::table('categories')->where('name', 'Other')->where('type', $type)->exists();

            if (! $exists) {
                DB::table('categories')->insert([
                    'name' => 'Other',
                    'type' => $type,
                    'description' => 'Catch-all. Tickets of a deleted category move here.',
                    'is_active' => true,
                    'sort_order' => 999,
                    'is_hidden' => false,
                    'is_locked' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('categories')->where('name', 'Other')->where('type', $type)->update(['is_locked' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_hidden', 'is_locked']);
        });
    }
};
