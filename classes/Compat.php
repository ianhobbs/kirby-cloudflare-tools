<?php

namespace IanHobbs\CloudflareTools;

use Kirby\Cms\App;

/**
 * The one place that knows which Kirby is running.
 *
 * 2.x runs on Kirby 6 only. Kirby 5 has the Vue 2 Panel and the Panel APIs
 * 1.x uses, so Kirby 5 sites keep plugin 1.x. Composer installs are stopped
 * by the `conflict` rule in composer.json; this covers ZIP and submodule
 * installs, which Composer never sees.
 */
final class Compat
{
    /** The Kirby major version 2.x runs on */
    public const KIRBY = 6;

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

    public static function supported(): bool
    {
        return self::major() === self::KIRBY;
    }

    /** Why the plugin switched itself off, for the error log */
    public static function message(): string
    {
        $message = '[' . Options::PREFIX . '] Cloudflare Tools 2.x runs on Kirby ' . self::KIRBY . ' only. This site runs Kirby ' . self::version() . ', so the plugin is switched off.';

        if (self::major() === 5) {
            $message .= ' Kirby 5 sites need Cloudflare Tools 1.x.';
        }

        return $message;
    }
}
