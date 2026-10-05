<?php

use IanHobbs\CloudflareTools\Api\Cache;
use IanHobbs\CloudflareTools\ApiException;

beforeEach(function () {
    $this->fake = fake()->on('POST', 'zones/{zone}/purge_cache', ['result' => ['id' => TEST_ZONE]]);
});

it('purges everything', function () {
    cloudflare()->cache()->purgeEverything();

    expect($this->fake->last()['body'])->toBe(['purge_everything' => true]);
});

it('purges URLs in batches', function () {
    $urls = array_map(fn ($i) => 'https://example.com/page-' . $i, range(1, 65));

    expect(cloudflare()->cache()->purgeUrls($urls))->toBe(65)
        ->and($this->fake->requests)->toHaveCount(3)
        ->and($this->fake->requests[0]['body']['files'])->toHaveCount(Cache::URLS_PER_REQUEST)
        ->and($this->fake->requests[2]['body']['files'])->toHaveCount(5);
});

it('only purges full URLs', function () {
    cloudflare()->cache()->purgeUrls(['/blog']);
})->throws(InvalidArgumentException::class, 'Not a full URL');

it('takes a textarea of items', function () {
    cloudflare()->cache()->purge('tags', "blog, news\n\n  products  \nblog");

    expect($this->fake->last()['body'])->toBe(['tags' => ['blog', 'news', 'products']]);
});

it('checks cache tags are printable ASCII', function () {
    cloudflare()->cache()->purgeTags(['two words']);
})->throws(InvalidArgumentException::class, 'printable ASCII');

it('strips the scheme from prefixes and needs a path', function () {
    cloudflare()->cache()->purgePrefixes(['https://www.example.com/blog']);

    expect($this->fake->last()['body'])->toBe(['prefixes' => ['www.example.com/blog']]);

    cloudflare()->cache()->purgePrefixes(['www.example.com']);
})->throws(InvalidArgumentException::class, 'host and a path');

it('normalises hostnames', function () {
    cloudflare()->cache()->purgeHosts(['https://WWW.example.com/', 'www.example.com']);

    expect($this->fake->last()['body'])->toBe(['hosts' => ['www.example.com']]);
});

it('refuses an empty purge', function () {
    cloudflare()->cache()->purge('urls', "  \n ");
})->throws(InvalidArgumentException::class, 'Nothing to purge');

it('refuses an unknown purge type', function () {
    cloudflare()->cache()->purge('files', ['x']);
})->throws(InvalidArgumentException::class, 'Unknown purge type');

it('passes Cloudflare\'s refusal on', function () {
    fake()->on('POST', 'zones/{zone}/purge_cache', [
        'success' => false,
        'errors'  => [['code' => 10000, 'message' => 'Authentication error']],
    ], 403);

    cloudflare()->cache()->purgeEverything();
})->throws(ApiException::class, 'Authentication error (10000)');

it('switches Tiered Cache and Smart Topology', function () {
    $fake = fake()
        ->on('PATCH', 'zones/{zone}/argo/tiered_caching', fn ($body) => ['result' => ['value' => $body['value']]])
        ->on('PATCH', 'zones/{zone}/cache/tiered_cache_smart_topology_enable', fn ($body) => ['result' => ['value' => $body['value']]]);

    expect(cloudflare()->cache()->setTieredCaching(true))->toBeTrue()
        ->and(cloudflare()->cache()->setSmartTopology(false))->toBeFalse()
        ->and(array_column($fake->requests, 'body'))->toBe([['value' => 'on'], ['value' => 'off']]);
});
