<?php

namespace IanHobbs\CloudflareTools;

use Closure;

/**
 * Reads the plugin's options, and the environment the credentials come from.
 *
 * Every option may be a closure, so a site can defer reading a secret until
 * the plugin needs it: `'token' => fn () => env('CLOUDFLARE_API_TOKEN')`.
 * Kirby's option() returns closures as they are, so they are called here.
 */
final class Options
{
    public const PREFIX = 'ianhobbs.cloudflare-tools';

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = option(self::PREFIX . '.' . $key, $default);

        return $value instanceof Closure ? $value() : $value;
    }

    /**
     * An environment value, or null when it is unset or blank.
     *
     * Prefers the env() helper of johannschopplich/kirby-helpers, which reads
     * the .env file that config.php loaded with Env::load(). Falls back to the
     * real process environment, so a host that sets variables itself (Docker,
     * Forge, a PHP-FPM pool) needs no .env file at all.
     */
    public static function env(string $key): string|null
    {
        $value = function_exists('env') ? env($key) : null;
        $value ??= $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return self::filled($value);
    }

    /** Trims a string option; anything blank becomes null */
    public static function filled(mixed $value): string|null
    {
        if (is_string($value) === false && is_numeric($value) === false) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
