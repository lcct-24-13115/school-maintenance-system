<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RequestAttachment extends Model
{
    /** Disk used for new uploads. Phase 4/9 moves this to a private disk served through an authorized route. */
    public const DISK = 'public';

    protected $fillable = ['request_id', 'uploaded_by', 'path', 'original_name', 'mime_type'];

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
