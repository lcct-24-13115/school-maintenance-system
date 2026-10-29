<?php

namespace App\Models;

use App\Services\AttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

class RequestAttachment extends Model
{
    /** Disk used for NEW uploads: private (storage/app/private), served only through attachments.show. */
    public const DISK = 'local';

    /** Where files were saved before Phase 3. Moved by `php artisan attachments:move-to-private`. */
    public const LEGACY_DISK = 'public';

    public const KIND_REQUEST = 'request';

    public const KIND_SIGNED_EVIDENCE = 'signed_evidence';

    public const KIND_WORK_PHOTO = 'work_photo';

    public const KINDS = [self::KIND_REQUEST, self::KIND_SIGNED_EVIDENCE, self::KIND_WORK_PHOTO];

    protected $fillable = [
        'request_id', 'uploaded_by', 'path', 'disk', 'kind', 'original_name', 'mime_type', 'size_bytes',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public static function kindLabels(): array
    {
        return [
            self::KIND_REQUEST => 'Request file',
            self::KIND_SIGNED_EVIDENCE => 'Signed evidence',
            self::KIND_WORK_PHOTO => 'Work photo',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind ?? self::KIND_REQUEST] ?? ucfirst(str_replace('_', ' ', (string) $this->kind));
    }

    public function isRequestFile(): bool
    {
        return ($this->kind ?? self::KIND_REQUEST) === self::KIND_REQUEST;
    }

    /** The disk this file is on (a row without a value is a new-style private file). */
    public function storageDisk(): string
    {
        return $this->disk ?: self::DISK;
    }

    /** Images and PDFs (detected type, whitelisted in config) may be shown in the browser; all else downloads. */
    public function isInline(): bool
    {
        return in_array((string) $this->mime_type, AttachmentService::inlineMimes(), true);
    }

    public function isImage(): bool
    {
        return $this->isInline() && str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /** Content-Type we send. Unknown/legacy types are served as a plain download. */
    public function servedMime(): string
    {
        return in_array((string) $this->mime_type, AttachmentService::knownMimes(), true)
            ? (string) $this->mime_type
            : 'application/octet-stream';
    }

    public function icon(): string
    {
        if ($this->isImage()) {
            return '🖼️';
        }

        return match (strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION))) {
            'pdf' => '📄',
            'docx' => '📝',
            'xlsx' => '📊',
            default => '📎',
        };
    }

    public function sizeForHumans(): string
    {
        return $this->size_bytes === null ? '—' : Number::fileSize($this->size_bytes, precision: 1);
    }

    /** The ONLY way to link to a file: the authorized route. Never a /storage URL. */
    public function url(): string
    {
        return route('attachments.show', $this);
    }

    public function downloadUrl(): string
    {
        return route('attachments.show', ['attachment' => $this, 'download' => 1]);
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
