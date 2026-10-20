<?php

namespace App\Rules;

use App\Services\AttachmentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates ONE uploaded file against config/school.php -> attachments (type by real content, size,
 * double extensions, extension/content match). Use on every file input:
 *
 *   'files'   => ['nullable', 'array', 'max:'.AttachmentService::maxFiles()],
 *   'files.*' => [new ValidAttachment],
 */
class ValidAttachment implements ValidationRule
{
    /** Run even when PHP rejected the upload (e.g. bigger than upload_max_filesize) instead of silently skipping it. */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! $value instanceof UploadedFile) {
            $fail('Please choose a file to upload.');

            return;
        }

        if ($error = AttachmentService::check($value)) {
            $fail($error);
        }
    }
}
