<?php

/**
 * Cloudflare Tools for Kirby
 *
 * A Cloudflare control panel in the Panel — cache purge, zone settings,
 * security, SSL/TLS, DNS and traffic — plus CLI/Janitor commands, a page
 * "Purge" button, optional purge-on-save, and an open PHP API for the rest:
 *
 *   site()->cloudflare()->cache()->purgeUrls([$page->url()]);
 *
 * The API token and zone ID stay on the server: read from the environment
 * (a .env file outside the web root, see README "Credentials"), used only
 * for server-side calls, never sent to the browser.
 */

use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Licensing\LicenseManager;
use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Panel\Guard;
use Kirby\Cms\App as Kirby;
use Kirby\Plugin\Plugin;

// Composer installs autoload the classes already; a plain copy into
// site/plugins/ (zip download, git submodule) loads them from here
if (class_exists(Cloudflare::class) === false) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IanHobbs\\CloudflareTools\\';

        if (str_starts_with($class, $prefix) === true) {
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file) === true) {
                require $file;
            }
        }
    });
}

Kirby::plugin(
    name: 'ianhobbs/cloudflare-tools',
    version: '1.0.0-beta.1',
    // Shown in the Panel's System view, with a status that opens the
    // activation dialog. Read from the stored state: no network call.
    license: fn (Plugin $plugin) => LicenseManager::fromOptions()->toPanel($plugin),
    extends: [
        'options' => [
            // ── Credentials ─────────────────────────────────────────────────
            // Read from the environment by default, so a site that loads a
            // .env file (or whose host sets variables) needs no config at all.
            // Override in config.php with env('…') or a closure — never paste
            // a token into a file that is committed or deployed.
            'token'   => fn () => Options::env('CLOUDFLARE_API_TOKEN'),
            'zone'    => fn () => Options::env('CLOUDFLARE_ZONE_ID'),
            // optional: find the zone from a domain instead of a zone ID
            'domain'  => fn () => Options::env('CLOUDFLARE_DOMAIN'),
            // optional: only needed for account-owned tokens and account APIs
            'account' => fn () => Options::env('CLOUDFLARE_ACCOUNT_ID'),
            // legacy Global API Key auth; a scoped token is strongly preferred
            'email'   => fn () => Options::env('CLOUDFLARE_API_EMAIL'),
            'key'     => fn () => Options::env('CLOUDFLARE_API_KEY'),

            // ── API ─────────────────────────────────────────────────────────
            'endpoint' => 'https://api.cloudflare.com/client/v4',
            'timeout'  => 15,
            // extra API gates: ['name' => MyEndpoint::class], see Cloudflare::extend()
            'endpoints' => [],

            // ── Caching of API reads (zone lookup, analytics) ──────────────
            'cache'    => true,
            'cacheTtl' => 10, // minutes; 0 turns it off

            // ── Purging ─────────────────────────────────────────────────────
            // flush Kirby's pages cache before purging everything
            'flushKirby'    => true,
            // purge a page's URLs (plus parents and home) whenever it changes
            'autoPurge'     => false,
            // …and everything when the site's own fields change
            'autoPurgeSite' => true,
            // include parents and the home page in a page's purge
            'purgeParents'  => true,
            // replace the URL list for a page: fn (Page $page): array
            'pageUrls'      => null,
            // a small file Cloudflare caches, to see a purge reach the edge;
            // null uses the site's /favicon.ico when the site URL is absolute
            'probe'         => null,

            // ── Panel ───────────────────────────────────────────────────────
            'panel' => [
                'label' => 'Cloudflare',
                'icon'  => 'cloud',
                'menu'  => true,
                // tabs to leave out, e.g. ['dns', 'speed']
                'hide'  => [],
            ],
            // DNS in the Panel: 'read' (default), 'write', or false for no tab
            'dns'         => 'read',
            'defaultDays' => 7,

            // ── Licence (see LICENSE) ───────────────────────────────────────
            // the key; can also be entered in the Panel (System › plugins)
            'license'       => fn () => Options::env('CLOUDFLARE_TOOLS_LICENSE'),
            // more development hosts that need no licence, e.g. ['staging.*']
            'licenseIgnore' => [],
            // where the "Cloudflare Tools Licence" name links to
            'licenseShop'   => null,
            // the vendor's own testing: ['organization' => '…', 'endpoint' => Licensing\Polar::SANDBOX]
            'licenseServer' => [],
        ],

        // Role permissions, defaults for roles that don't set them.
        // Admins may always do everything. See Panel\Guard.
        'permissions' => Guard::PERMISSIONS,

        'api' => [
            'routes' => require __DIR__ . '/config/api.php',
        ],

        'areas'    => require __DIR__ . '/config/areas.php',
        'commands' => require __DIR__ . '/config/commands.php',
        'hooks'    => require __DIR__ . '/config/hooks.php',

        'siteMethods' => [
            /**
             * The connector, from the options; pass overrides for another
             * zone or other credentials:
             *   site()->cloudflare(['zone' => '…'])->settings()->all()
             */
            'cloudflare' => fn (array $config = []) => Cloudflare::fromOptions($config),
        ],
    ]
);
