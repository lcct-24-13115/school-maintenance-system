<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 (additive): where a file lives, what it is for, how big it is.
 *  - disk       'local' (private) for new rows. Rows that exist BEFORE this migration were stored on the
 *               public disk, so they are backfilled to 'public' until `php artisan attachments:move-to-private`.
 *  - kind       request | signed_evidence | work_photo
 *  - size_bytes nullable (old rows are filled in when they are moved)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->string('disk', 20)->default('local')->after('path');
            $table->string('kind', 20)->default('request')->after('disk');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');

            $table->index(['request_id', 'kind'], 'request_attachments_kind_idx');
        });

        // Everything that existed before this migration was saved on the public disk.
        DB::table('request_attachments')->update(['disk' => 'public']);
    }

    public function down(): void
    {
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->dropIndex('request_attachments_kind_idx');
            $table->dropColumn(['disk', 'kind', 'size_bytes']);
        });
    }
};
