<?php

namespace IanHobbs\CloudflareTools;

use Kirby\Cms\App;

/**
 * The one place that knows which Kirby is running.
 *
 * 1.x runs on Kirby 5 only. Kirby 6 moves the Panel to Vue 3 and changes
 * Panel APIs this line uses, so it gets plugin 2.x. Composer installs are
 * stopped by the `conflict` rule in composer.json; this covers ZIP and
 * submodule installs, which Composer never sees.
 */
final class Compat
{
    /** The Kirby major version 1.x runs on */
    public const KIRBY = 5;

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
        $message = '[' . Options::PREFIX . '] Cloudflare Tools 1.x runs on Kirby ' . self::KIRBY . ' only. This site runs Kirby ' . self::version() . ', so the plugin is switched off.';

        if (self::major() > self::KIRBY) {
            $message .= ' Kirby 6 sites need Cloudflare Tools 2.x.';
        }

        return $message;
    }
}
