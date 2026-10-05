<?php

namespace IanHobbs\CloudflareTools;

use Closure;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Throwable;

/**
 * Turns Kirby pages into the URLs Cloudflare has cached for them, and purges
 * those — from the page's Panel button, or on save when `autoPurge` is on.
 *
 * A page's URLs are its own in every language, plus — while `purgeParents`
 * is on, the default — its parents' and the home page's, since listings
 * often show its title or teaser. A site with other dependencies (tag pages,
 * feeds) can replace the list with the `pageUrls` option:
 * fn (Page $page): array.
 */
final class Purger
{
    public function __construct(private Cloudflare $cloudflare)
    {
    }

    public static function fromOptions(): self
    {
        return new self(Cloudflare::fromOptions());
    }

    /** @return string[] full URLs */
    public static function urlsForPage(Page $page): array
    {
        $custom = option(Options::PREFIX . '.pageUrls');

        if ($custom instanceof Closure) {
            return self::absolute($custom($page));
        }

        $urls  = self::languageUrls($page);
        $kirby = $page->kirby();

        if (Options::get('purgeParents', true) === true) {
            foreach ($page->parents() as $parent) {
                $urls = [...$urls, ...self::languageUrls($parent)];
            }

            $urls = [...$urls, ...self::languageUrls($kirby->site()->homePage())];
        }

        return self::absolute($urls);
    }

    /** Purges a page's URLs; returns how many were sent */
    public function purgePage(Page $page): int
    {
        $urls = self::urlsForPage($page);

        if ($urls === []) {
            throw new ApiException('This page has no full URL to purge: set Kirby\'s `url` option to the site\'s address');
        }

        return $this->cloudflare->cache()->purgeUrls($urls);
    }

    /**
     * For hooks: purges the given pages' URLs in one go, and never lets
     * Cloudflare being unreachable break a save. Failures go to the log.
     */
    public function purgeQuietly(Page|File|null ...$models): void
    {
        try {
            if ($this->cloudflare->isConfigured() === false) {
                return;
            }

            $urls = [];

            foreach ($models as $model) {
                $page = $model instanceof File ? $model->page() : $model;

                if ($page instanceof Page) {
                    $urls = [...$urls, ...self::urlsForPage($page)];
                }
            }

            $urls = self::absolute($urls);

            if ($urls !== []) {
                $this->cloudflare->cache()->purgeUrls($urls);
            }
        } catch (Throwable $e) {
            self::log('Cloudflare purge after save failed: ' . $e->getMessage());
        }
    }

    /** For the site.update hook, where every page may show the changed field */
    public function purgeEverythingQuietly(): void
    {
        try {
            if ($this->cloudflare->isConfigured()) {
                $this->cloudflare->cache()->purgeEverything();
            }
        } catch (Throwable $e) {
            self::log('Cloudflare purge after save failed: ' . $e->getMessage());
        }
    }

    /** @return string[] */
    private static function languageUrls(Page|null $page): array
    {
        if ($page === null) {
            return [];
        }

        $kirby = $page->kirby();

        if ($kirby->multilang() === false) {
            return [$page->url()];
        }

        return array_map(
            fn ($language) => $page->url($language->code()),
            $kirby->languages()->values()
        );
    }

    /**
     * Keeps only full http(s) URLs, once each. Relative ones (Kirby without
     * a `url` option, on the command line) mean nothing to Cloudflare.
     *
     * @return string[]
     */
    private static function absolute(array $urls): array
    {
        $urls = array_filter(
            array_map(fn ($url) => trim((string)$url), $urls),
            fn ($url) => preg_match('~^https?://~i', $url) === 1
        );

        return array_values(array_unique($urls));
    }

    private static function log(string $message): void
    {
        error_log('[' . Options::PREFIX . '] ' . $message);
    }
}

