<?php

use IanHobbs\CloudflareTools\Api\Endpoint;
use IanHobbs\CloudflareTools\Api\Zone;
use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Cloudflare;

it('rejects a zone ID that is not 32 hex characters', function () {
    fake(['zone' => 'example.com']);

    cloudflare()->zoneId();
})->throws(ApiException::class, '32-character Zone ID');

it('looks the zone up from the domain, walking up to the parent', function () {
    $fake = fake(['zone' => null, 'domain' => 'https://www.shop.example.com/path']);

    $fake->on('GET', 'zones', fn ($body, $query) => [
        'result' => $query['name'] === 'example.com' ? [['id' => TEST_ZONE]] : [],
    ]);

    expect(cloudflare()->zoneId())->toBe(TEST_ZONE)
        ->and(array_column($fake->requests, 'query'))->toBe([
            ['name' => 'www.shop.example.com', 'status' => 'active'],
            ['name' => 'shop.example.com', 'status' => 'active'],
            ['name' => 'example.com', 'status' => 'active'],
        ]);
});

it('says so when no zone serves the domain', function () {
    fake(['zone' => null, 'domain' => 'example.org'])->on('GET', 'zones', ['result' => []]);

    cloudflare()->zoneId();
})->throws(ApiException::class, 'No active Cloudflare zone found for "example.org"');

it('normalises domains', function (string $input, string $expected) {
    expect(Zone::normaliseDomain($input))->toBe($expected);
})->with([
    ['https://example.com/a?b=1', 'example.com'],
    ['EXAMPLE.com:8443', 'example.com'],
    ['sub.example.co.uk/', 'sub.example.co.uk'],
    ['http://a.b.com#frag', 'a.b.com'],
]);

it('rejects things that are not domains', function (string $input) {
    Zone::normaliseDomain($input);
})->with(['not a domain', 'localhost', '   '])->throws(InvalidArgumentException::class);

it('fills {zone} into raw API paths', function () {
    $fake = fake()->on('GET', 'zones/{zone}/settings/http3', ['result' => ['value' => 'on']]);

    $value = cloudflare()->request('GET', 'zones/{zone}/settings/http3')->result()['value'];

    expect($value)->toBe('on')
        ->and($fake->calls())->toBe(['GET zones/' . TEST_ZONE . '/settings/http3']);
});

it('fills {account} from the zone when no account ID is configured', function () {
    $fake = fake()
        ->on('GET', 'zones/{zone}', ['result' => ['account' => ['id' => 'acc123']]])
        ->on('GET', 'accounts/acc123/rum/site_info/list', ['result' => []]);

    cloudflare()->request('GET', 'accounts/{account}/rum/site_info/list');

    expect($fake->calls())->toContain('GET accounts/acc123/rum/site_info/list');
});

it('takes extra API gates', function () {
    $fake = fake()->on('GET', 'zones/{zone}/email/routing', ['result' => ['enabled' => true]]);

    Cloudflare::extend('emailRouting', EmailRoutingGate::class);

    expect(cloudflare()->emailRouting()->enabled())->toBeTrue();
});

it('only takes Endpoint subclasses as gates', function () {
    Cloudflare::extend('nope', stdClass::class);
})->throws(InvalidArgumentException::class);

it('says which gate does not exist', function () {
    cloudflare()->turnstile();
})->throws(BadMethodCallException::class, 'No Cloudflare endpoint called "turnstile"');

it('serves another zone with the same credentials', function () {
    $other = str_repeat('b', 32);
    $fake  = fake()->on('GET', 'zones/' . $other, ['result' => ['name' => 'other.com']]);

    expect(cloudflare()->forZone($other)->zone()->summary()['name'])->toBe('other.com')
        ->and($fake->last()['headers']['Authorization'])->toBe('Bearer test-token');
});

final class EmailRoutingGate extends Endpoint
{
    public function enabled(): bool
    {
        return (bool)($this->client()->get($this->zonePath('email/routing'))->result()['enabled'] ?? false);
    }
}
