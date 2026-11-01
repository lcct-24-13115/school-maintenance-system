<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A printable QR code (Phase 17). Stores only what it points to, a title and a colour; the picture itself is
 * drawn in the browser (resources/js/qr.js).
 *
 *  - login / register / repair : the link is built from APP_URL when shown, so it follows an address change.
 *  - custom                    : custom_url, stored as typed (http or https only, checked when saving).
 */
class QrCode extends Model
{
    public const KIND_LOGIN = 'login';

    public const KIND_REGISTER = 'register';

    public const KIND_REPAIR = 'repair';

    public const KIND_CUSTOM = 'custom';

    /** Dark squares must have at least this contrast against the white background, or phones struggle to read it. */
    public const MIN_CONTRAST = 3.0;

    public const DEFAULT_COLOR = '#1b3355';

    protected $fillable = ['title', 'kind', 'custom_url', 'color', 'created_by'];

    /** @return array<string, string> kind => label on the page */
    public static function kindLabels(): array
    {
        return [
            self::KIND_LOGIN => 'Login page',
            self::KIND_REGISTER => 'Register page',
            self::KIND_REPAIR => 'New repair request',
            self::KIND_CUSTOM => 'Custom link',
        ];
    }

    /** kind => route name, for the three built-in pages */
    private static function routeFor(string $kind): ?string
    {
        return [
            self::KIND_LOGIN => 'login',
            self::KIND_REGISTER => 'register',
            self::KIND_REPAIR => 'repairs.create',
        ][$kind] ?? null;
    }

    /** The address the built-in links start with: APP_URL, never the address the admin happens to be browsing on. */
    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** True when APP_URL is an address a phone cannot reach (localhost, 127.0.0.1, *.test, *.local ...). */
    public static function baseIsLocal(): bool
    {
        $host = strtolower((string) parse_url(self::baseUrl(), PHP_URL_HOST));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)) {
            return true;
        }

        foreach (['.test', '.local', '.localhost'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Public registration is closed by default (config/school.php); its page then answers 404. */
    public static function registrationOpen(): bool
    {
        return (bool) config('school.allow_public_registration');
    }

    /** What the QR holds: the full link a phone will open. */
    public function getUrlAttribute(): string
    {
        if ($this->kind === self::KIND_CUSTOM) {
            return (string) $this->custom_url;
        }

        $route = self::routeFor((string) $this->kind);

        return $route === null ? '' : self::baseUrl().route($route, [], false);
    }

    public function getKindLabelAttribute(): string
    {
        return self::kindLabels()[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** WCAG contrast ratio of a #rrggbb colour against white (1 = same as white, 21 = black). */
    public static function contrast(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $linear = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $linear(substr($hex, 0, 2))
            + 0.7152 * $linear(substr($hex, 2, 2))
            + 0.0722 * $linear(substr($hex, 4, 2));

        return 1.05 / ($luminance + 0.05);
    }
}
