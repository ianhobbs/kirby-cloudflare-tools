<?php

/**
 * Cloudflare Tools — Test Bootstrap
 *
 * Boots a minimal Kirby with the plugin loaded straight from the repo root,
 * against fixture roots, so no real site is needed. Nothing reaches
 * Cloudflare: every test answers for it with FakeCloudflare (see Pest.php),
 * plugged in through the plugin's `transport` option.
 */

require_once __DIR__ . '/../kirby/bootstrap.php';

// registers the plugin (Kirby::plugin() is safe before new Kirby())
require_once __DIR__ . '/../../index.php';

require_once __DIR__ . '/FakeCloudflare.php';

// Whoops would register error handlers on every boot, which PHPUnit
// reports as risky; Kirby's own suite switches it off the same way
Kirby\Cms\App::$enableWhoops = false;

// cached API reads from an earlier run must not answer for this one
Kirby\Filesystem\Dir::remove(__DIR__ . '/fixtures/cache');

const TEST_ZONE = '023e105f4ecef8ad9ca31a8372d0c353';

/**
 * A fresh Kirby per test that needs one, so options and the fake don't leak.
 * Pass plugin options to override the defaults below.
 */
function bootKirby(array $plugin = [], array $props = []): Kirby\Cms\App
{
    $fixtures = __DIR__ . '/fixtures';

    return new Kirby\Cms\App(array_replace_recursive([
        'roots' => [
            'index'    => $fixtures,
            'base'     => $fixtures,
            'content'  => $fixtures . '/content',
            'site'     => $fixtures . '/site',
            'accounts' => $fixtures . '/site/accounts',
            'sessions' => $fixtures . '/site/sessions',
            'media'    => $fixtures . '/media',
            'cache'    => $fixtures . '/cache',
            'logs'     => $fixtures . '/logs',
        ],
        'urls' => [
            'index' => 'https://example.com',
        ],
        'options' => [
            'debug' => false,
            'api'   => ['allowImpersonation' => true],
            'ianhobbs.cloudflare-tools' => [
                'token'     => 'test-token',
                'zone'      => TEST_ZONE,
                'cacheTtl'  => 0,
                'transport' => new FakeCloudflare(),
                ...$plugin,
            ],
        ],
        'users' => [
            ['email' => 'editor@example.com', 'role' => 'editor'],
            ['email' => 'client@example.com', 'role' => 'client'],
        ],
        'blueprints' => [
            'users/editor' => [
                'name'  => 'editor',
                'title' => 'Editor',
                // no plugin permissions set: the plugin defaults apply
            ],
            'users/client' => [
                'name'        => 'client',
                'title'       => 'Client',
                'permissions' => [
                    'access' => ['cloudflare-tools' => false],
                ],
            ],
        ],
    ], $props));
}

bootKirby();
