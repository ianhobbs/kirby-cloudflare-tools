<?php

use IanHobbs\CloudflareTools\Panel\Guard;
use Kirby\Exception\Exception;
use Kirby\Exception\PermissionException;

it('reports status without ever sending the token to the Panel', function () {
    fake()
        ->on('GET', 'zones/{zone}', ['result' => ['name' => 'example.com', 'status' => 'active', 'plan' => ['name' => 'Free Website'], 'account' => ['id' => 'acc', 'name' => 'Ian']]])
        ->on('GET', 'zones/{zone}/settings', ['result' => [
            ['id' => 'development_mode', 'value' => 'off', 'editable' => true],
            ['id' => 'security_level', 'value' => 'medium', 'editable' => true],
        ]]);

    $status = api('cloudflare-tools/status');

    expect($status['configured'])->toBeTrue()
        ->and($status['zone'])->toMatchArray(['name' => 'example.com', 'plan' => 'Free Website'])
        ->and($status['settings']['securityLevel'])->toBe('medium')
        ->and(json_encode($status))->not->toContain('test-token');
});

it('keeps the tab working when one read is refused', function () {
    fake()->on('GET', 'zones/{zone}', ['result' => ['name' => 'example.com']]);

    $status = api('cloudflare-tools/status');

    expect($status['zone']['name'])->toBe('example.com')
        ->and($status['settings'])->toBeNull()
        ->and($status['settingsError'])->toContain('Could not route');
});

it('purges from the Panel', function () {
    $fake = fake()->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    $result = api('cloudflare-tools/purge', 'POST', ['type' => 'urls', 'items' => "https://example.com/a\nhttps://example.com/b"]);

    expect($result['purged'])->toBe(2)
        ->and($fake->last()['body'])->toBe(['files' => ['https://example.com/a', 'https://example.com/b']]);
});

it('turns Cloudflare errors into messages the Panel shows, without a 401', function () {
    fake()->on('POST', 'zones/{zone}/purge_cache', ['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401);

    try {
        api('cloudflare-tools/purge', 'POST', ['type' => 'everything']);
        $this->fail('No exception');
    } catch (Exception $e) {
        // a 401 would make the Panel log the user out
        expect($e->getHttpCode())->toBe(502)
            ->and($e->getMessage())->toContain('Invalid API Token (1000), token not recognised');
    }
});

it('lets editors purge but not change settings by default', function () {
    fake()->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    expect(api('cloudflare-tools/purge', 'POST', ['type' => 'urls', 'items' => ['https://example.com/a']], [], 'editor@example.com')['purged'])->toBe(1);

    api('cloudflare-tools/settings/cache', 'PATCH', ['changes' => ['development_mode' => true]], [], 'editor@example.com');
})->throws(PermissionException::class);

it('keeps roles without area access out entirely', function () {
    kirby()->impersonate('client@example.com');

    expect(Guard::all())->each->toBeFalse();

    api('cloudflare-tools/purge', 'POST', ['type' => 'everything'], [], 'client@example.com');
})->throws(PermissionException::class);

it('keeps roles without area access from reading too', function (string $route) {
    fake();

    api($route, 'GET', [], [], 'client@example.com');
})->with(['cloudflare-tools/status', 'cloudflare-tools/settings/ssl', 'cloudflare-tools/dns', 'cloudflare-tools/security'])
  ->throws(PermissionException::class);

it('lets admins do everything', function () {
    kirby()->impersonate('kirby');

    expect(Guard::all())->each->toBeTrue();
});

it('only changes settings of the group it was asked for', function () {
    fake();

    // ssl belongs to the ssl group, so the cache route must not touch it
    api('cloudflare-tools/settings/cache', 'PATCH', ['changes' => ['ssl' => 'off']]);
})->throws(Kirby\Exception\InvalidArgumentException::class, 'not part of cache');

it('keeps DNS read-only unless the site allows writes', function () {
    fake()->on('GET', 'zones/{zone}/dns_records', listReply([]));

    expect(api('cloudflare-tools/dns')['writable'])->toBeFalse();

    api('cloudflare-tools/dns', 'POST', ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.7']);
})->throws(PermissionException::class, 'read-only');

it('writes DNS when the site allows it', function () {
    $fake = fake(['dns' => 'write'])->on('POST', 'zones/{zone}/dns_records', fn ($body) => ['result' => ['id' => 'n1'] + $body]);

    $record = api('cloudflare-tools/dns', 'POST', ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.7', 'proxied' => true]);

    expect($record['id'])->toBe('n1')
        ->and($fake->last()['body'])->toBe(['type' => 'A', 'name' => 'x', 'content' => '203.0.113.7', 'proxied' => true]);
});

it('loads the page purge dialog with the URLs it will clear', function () {
    kirby()->impersonate('kirby');

    $dialog = kirby()->extensions('areas')[Guard::AREA][0](kirby())['dialogs']['cloudflare-tools.purge-page'];
    $result = $dialog['load']('blog+hello');

    expect($result['component'])->toBe('k-text-dialog')
        ->and($result['props']['text'])->toContain('https://example.com/blog/hello')
        ->and($result['props']['submitButton']['text'])->toBe('Purge 3 URLs');
});
