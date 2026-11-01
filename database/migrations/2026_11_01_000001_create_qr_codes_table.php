<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17: QR codes. A row only remembers WHAT the code points to (kind, or custom_url for a custom link),
 * its title and its colour. The link of Login / Register / New repair request is worked out when it is shown,
 * from APP_URL, so it stays right if the school's address changes. The picture is drawn in the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('kind', 20);                         // login | register | repair | custom
            $table->string('custom_url', 2000)->nullable();     // only when kind = custom
            $table->string('color', 7)->default('#1b3355');     // the dark squares, #rrggbb. The background is always white.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
