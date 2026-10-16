<?php

use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Compat;

/**
 * Helpers shared across the suite. Each test that talks to "Cloudflare"
 * starts from fake(): a fresh Kirby whose plugin transport is a new
 * FakeCloudflare, returned so the test can register replies and inspect
 * what was sent.
 */

uses()->beforeEach(function () {
    // a licence saved by one test must not license the next
    if (is_file(licenseFile()) === true) {
        unlink(licenseFile());
    }
    bootKirby();
})->in('Feature');

/**
 * Whether the suite runs on Kirby 4, the lite edition. Tests of what only
 * Kirby 5 has (the licence, the other tabs' routes, view buttons) skip with
 * ->skip(lite(), …); the CI matrix runs the suite on both.
 */
function lite(): bool
{
    return Compat::lite();
}

/** Where the plugin keeps the licence state for the fixture site */
function licenseFile(): string
{
    return __DIR__ . '/fixtures/site/config/.cloudflare-tools-license';
}

/** A fresh Kirby with a new fake Cloudflare behind it */
function fake(array $plugin = []): FakeCloudflare
{
    $fake = new FakeCloudflare();
    bootKirby(['transport' => $fake, ...$plugin]);

    return $fake;
}

/** The connector, built from the options the way the plugin builds it */
function cloudflare(array $overrides = []): Cloudflare
{
    return Cloudflare::fromOptions($overrides);
}

/** Calls a Panel API route as the given user ('kirby' is an admin) */
function api(string $path, string $method = 'GET', array $body = [], array $query = [], string $user = 'kirby'): mixed
{
    $kirby = kirby();
    $kirby->impersonate($user);

    return $kirby->api()->call($path, $method, ['body' => $body, 'query' => $query]);
}

/** A list response the way Cloudflare pages them */
function listReply(array $result, int $page = 1, int $pages = 1): array
{
    return [
        'result'      => $result,
        'result_info' => ['page' => $page, 'total_pages' => $pages, 'total_count' => count($result)],
    ];
}
