<?php

use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Panel\Guard;
use Kirby\Cms\App;

it('registers the plugin with its version', function () {
    $plugin = App::plugin('ianhobbs/cloudflare-tools');

    expect($plugin)->not->toBeNull()
        ->and($plugin->version())->toBe('1.0.2');
});

it('follows Kirby\'s naming convention: kirby- prefix outside Kirby only', function () {
    $composer = json_decode(file_get_contents(__DIR__ . '/../../../composer.json'), true);

    // GitHub, Packagist and Polar
    expect($composer['name'])->toBe('ianhobbs/kirby-cloudflare-tools')
        ->and($composer['homepage'])->toBe('https://github.com/ianhobbs/kirby-cloudflare-tools')
        // inside Kirby
        ->and(App::plugin('ianhobbs/cloudflare-tools'))->not->toBeNull()
        ->and($composer['extra']['installer-name'])->toBe('cloudflare-tools')
        ->and($composer['extra']['kirby']['compatible'])->toBe('^5.0');
});

it('shows its licence in the System view, with a status that opens the dialog', function () {
    $license = App::plugin('ianhobbs/cloudflare-tools')->license()->toArray();

    // the fixture site is public (https://example.com) and has no key
    expect($license['name'])->toBe('Cloudflare Tools Licence')
        ->and($license['status'])->toMatchArray([
            'value'  => 'missing',
            'label'  => 'Activate',
            'dialog' => 'cloudflare-tools/license',
        ]);
});

it('registers the Panel area, page button and purge dialog', function () {
    $areas = kirby()->extensions('areas');

    expect($areas)->toHaveKey(Guard::AREA);

    $area = $areas[Guard::AREA][0](kirby());

    expect($area['icon'])->toBe('cloud')
        ->and($area['buttons'])->toHaveKey('cloudflare-purge')
        ->and($area['dialogs'])->toHaveKey('cloudflare-tools.purge-page');
});

it('registers its API routes under cloudflare-tools/', function () {
    $patterns = array_column(kirby()->extensions('api')['routes'], 'pattern');

    expect($patterns)->toContain('cloudflare-tools/status')
        ->toContain('cloudflare-tools/purge')
        ->toContain('cloudflare-tools/settings/(:any)')
        ->toContain('cloudflare-tools/dns')
        ->toContain('cloudflare-tools/diagnostics');
});

it('registers the CLI commands', function () {
    expect(kirby()->extensions('commands'))
        ->toHaveKeys(['cloudflare-tools:check', 'cloudflare-tools:purge', 'cloudflare-tools:api', 'cloudflare-tools:license']);
});

it('registers role permissions with safe defaults', function () {
    expect(kirby()->extensions('permissions'))->toHaveKey(Options::PREFIX)
        ->and(Guard::PERMISSIONS)->toMatchArray([
            'purge'    => true,
            'settings' => false,
            'security' => false,
            'dns'      => false,
        ]);
});

it('exposes the connector as a site method', function () {
    expect(site()->cloudflare())->toBeInstanceOf(Cloudflare::class)
        ->and(site()->cloudflare()->zoneId())->toBe(TEST_ZONE);
});

it('reads the credentials from the environment by default', function () {
    // the options without the test's overrides: only the plugin's defaults
    bootKirby(['token' => fn () => Options::env('CF_TEST_TOKEN'), 'zone' => fn () => Options::env('CF_TEST_ZONE')]);

    putenv('CF_TEST_TOKEN=from-env');
    putenv('CF_TEST_ZONE=' . TEST_ZONE);

    $cf = cloudflare();

    expect($cf->isConfigured())->toBeTrue()
        ->and($cf->zoneId())->toBe(TEST_ZONE);

    putenv('CF_TEST_TOKEN');
    putenv('CF_TEST_ZONE');
});

it('treats blank environment values as missing', function () {
    putenv('CF_TEST_BLANK=   ');

    expect(Options::env('CF_TEST_BLANK'))->toBeNull()
        ->and(Options::env('CF_TEST_UNSET_' . uniqid()))->toBeNull();

    putenv('CF_TEST_BLANK');
});

it('tells what is missing when nothing is configured', function () {
    bootKirby(['token' => null, 'zone' => null, 'domain' => null]);

    $cf = cloudflare();

    expect($cf->isConfigured())->toBeFalse()
        ->and($cf->missing())->toBe(['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ZONE_ID']);
});
