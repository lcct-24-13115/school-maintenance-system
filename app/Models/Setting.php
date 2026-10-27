<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Editable key/value settings (Merge Phase M1).
 *
 *   Setting::get('sla_hours_routine')      string|null  (falls back to config/school.php -> settings_defaults)
 *   Setting::int('sla_hours_routine')      int
 *   Setting::put('reopen_window_days', 30) save + clear the cache
 */
class Setting extends Model
{
    private const CACHE_KEY = 'app.settings';

    protected $fillable = ['key', 'value'];

    /** @return array<string, ?string> every stored setting, cached until a setting changes */
    public static function values(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            return [];   // table not migrated yet: fall back to the config defaults
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::values()[$key] ?? config("school.settings_defaults.{$key}", $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);

        Cache::forget(self::CACHE_KEY);
    }
}
