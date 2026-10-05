<?php

// The pattern the README documents, as a site would use it: secrets live in
// a folder one level above the web root (here the repo's /glue, beside dev/),
// never in config.php, never in git, never deployed.
$secrets = dirname(__DIR__, 3) . '/glue';

// Dotenv throws on a missing file; without it the plugin just shows its
// "Connect Cloudflare" notice instead of the site crashing
if (is_file($secrets . '/.env')) {
    \JohannSchopplich\Helpers\Env::load($secrets);
}

return [
    'debug' => true,
    'panel' => [
        'install' => true,
        // the Purge button on every page view, after the defaults
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'settings', 'languages', 'status', 'cloudflare-purge'],
        ],
    ],
    'ianhobbs.cloudflare-tools' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone'  => env('CLOUDFLARE_ZONE_ID'),
        'dns'   => env('CLOUDFLARE_DNS_MODE', 'read'),
    ],
];
