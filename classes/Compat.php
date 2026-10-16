<?php

namespace IanHobbs\CloudflareTools;

use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Throwable;

/**
 * The one place that knows which Kirby is running.
 *
 * Kirby 5 runs the full plugin. Kirby 4.3+ runs the lite edition: the
 * Overview tab only (Development Mode, Purge everything, traffic), and no
 * licence. Everything that differs between the two asks this class, so the
 * Kirby 6 port (plugin 2.0) deletes the Kirby 4 branches here and the
 * `lite()` checks elsewhere, and nothing else.
 */
final class Compat
{
    /** The oldest Kirby the plugin runs on: Kirby::plugin(version:) arrived in 4.3 */
    public const MINIMUM = '4.3.0';

    /** The API routes the lite edition registers, by pattern */
    public const LITE_ROUTES = [
        'cloudflare-tools/status',
        'cloudflare-tools/analytics',
        'cloudflare-tools/purge',
        'cloudflare-tools/development-mode',
    ];

    private static string|null $version = null;

    /** Kirby's version, e.g. "5.6.1" */
    public static function version(): string
    {
        return self::$version ??= (string)App::version();
    }

    public static function major(): int
    {
        return (int)self::version();
    }

    /** Kirby 4.3 up to (not including) Kirby 6 */
    public static function supported(): bool
    {
        return version_compare(self::version(), self::MINIMUM, '>=') && self::major() < 6;
    }

    /** The Kirby 4 edition: Overview tab only, no licence */
    public static function lite(): bool
    {
        return self::major() === 4;
    }

    /**
     * A Kirby exception whose message the Panel shows exactly as given.
     *
     * Kirby 5 (and 6) take named arguments. Kirby 4 gets the message as a
     * plain string: its array arguments run the text through
     * Str::template(), which blanks anything in {braces}, and Cloudflare's
     * messages must reach the Panel unchanged. On Kirby 4 the exception
     * therefore has its class's default HTTP code (never a 401, which
     * would log the user out). Kirby 5 drops `$previous` when it is given a
     * message; Kirby 6 keeps it.
     *
     * @template T of Exception
     * @param class-string<T> $class
     * @return T
     */
    public static function exception(
        string $class,
        string $message,
        int|null $httpCode = null,
        Throwable|null $previous = null
    ): Exception {
        if (self::lite() === true) {
            return new $class($message);
        }

        return new $class(
            message: $message,
            httpCode: $httpCode,
            previous: $previous,
            translate: false
        );
    }
}
