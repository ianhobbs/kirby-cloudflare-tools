<?php

use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Licensing\LicenseManager;
use IanHobbs\CloudflareTools\Licensing\Polar;
use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

const ORG = '11111111-2222-3333-4444-555555555555';
const KEY = 'CFT-1234-5678-ABCD';

/** A Polar licence key object, as activate/validate return it */
function polarKey(array $overrides = []): array
{
    return [
        'id'                => 'lk_1',
        'status'            => 'granted',
        'display_key'       => '****-ABCD',
        'limit_activations' => 5,
        'expires_at'        => null,
        ...$overrides,
    ];
}

/** A fake Polar store with activate, validate and deactivate answering "yes" */
function polar(FakeCloudflare $fake): FakeCloudflare
{
    return $fake
        ->on('POST', '/v1/customer-portal/license-keys/activate', fn ($body) => [
            'id'          => 'act_1',
            'label'       => $body['label'],
            'license_key' => polarKey(),
        ])
        ->on('POST', '/v1/customer-portal/license-keys/validate', fn ($body) => polarKey([
            'activation' => ['id' => $body['activation_id'] ?? null],
        ]))
        ->on('POST', '/v1/customer-portal/license-keys/deactivate', [], 204);
}

/** A manager on the given site URL, with a clock the test can move */
function manager(FakeCloudflare $fake, string $url = 'https://example.com', string|null $key = null, int|null &$now = null, string|null $organization = ORG): LicenseManager
{
    $now ??= 1_800_000_000;

    return new LicenseManager(
        provider: new Polar($organization, Polar::ENDPOINT, $fake),
        file: licenseFile(),
        configuredKey: $key,
        url: $url,
        ignore: ['staging.*'],
        shop: 'https://shop.example.com',
        now: function () use (&$now) {
            return $now;
        }
    );
}

it('needs no licence on development sites', function (string $url) {
    expect(manager(new FakeCloudflare(), $url)->status())->toBe('demo');
})->with([
    'http://localhost:8000',
    'https://site.test',
    'https://site.ddev.site',
    'http://192.168.1.20',
    'https://staging.example.com',
]);

it('asks public websites for a licence', function () {
    $info = manager(new FakeCloudflare())->info();

    expect($info)->toMatchArray(['status' => 'missing', 'website' => 'example.com', 'source' => null, 'shop' => 'https://shop.example.com']);
});

it('doesn\'t guess when Kirby has no site URL (the command line)', function () {
    $info = manager(new FakeCloudflare(), '/')->info();

    expect($info['status'])->toBe('unknown')
        ->and($info['message'])->toContain('`url` option');
});

it('counts a root directory as part of the website', function () {
    expect(manager(new FakeCloudflare(), 'https://Example.com/shop/')->website())->toBe('example.com/shop');
});

it('activates a key for this website', function () {
    $fake = polar(new FakeCloudflare());
    $info = manager($fake)->activate(KEY);

    expect($fake->last()['url'])->toBe('https://api.polar.sh/v1/customer-portal/license-keys/activate')
        ->and($fake->last()['body'])->toBe(['key' => KEY, 'organization_id' => ORG, 'label' => 'example.com'])
        ->and($info)->toMatchArray(['status' => 'active', 'display' => '****-ABCD', 'limit' => 5, 'source' => 'panel']);

    $stored = json_decode(file_get_contents(licenseFile()), true);

    expect($stored['activation'])->toBe('act_1')
        ->and($stored['website'])->toBe('example.com');
});

it('never writes a key from the environment to disk', function () {
    $fake = polar(new FakeCloudflare());

    manager($fake, key: KEY)->activate();

    expect(file_get_contents(licenseFile()))->not->toContain(KEY)
        ->and(manager($fake, key: KEY)->info()['source'])->toBe('config');
});

it('re-checks instead of using a second activation', function () {
    $fake = polar(new FakeCloudflare());

    manager($fake)->activate(KEY);
    manager($fake)->activate();

    expect($fake->calls())->toBe([
        'POST /v1/customer-portal/license-keys/activate',
        'POST /v1/customer-portal/license-keys/validate',
    ])->and($fake->last()['body']['activation_id'])->toBe('act_1');
});

it('passes on a refusal, and saves nothing', function () {
    $fake = (new FakeCloudflare())->on('POST', '/v1/customer-portal/license-keys/activate', [
        'type'   => 'NotPermitted',
        'detail' => 'License key activation limit reached',
    ], 403);

    expect(fn () => manager($fake)->activate(KEY))
        ->toThrow(ApiException::class, 'already used on as many websites as it allows (License key activation limit reached)')
        ->and(file_exists(licenseFile()))->toBeFalse();
});

it('saves the key unverified until licence checks are switched on', function () {
    $fake = new FakeCloudflare();
    $info = manager($fake, organization: null)->activate(KEY);

    expect($info['status'])->toBe('unknown')
        ->and($info['message'])->toContain('once licence checks are switched on')
        ->and($fake->requests)->toBe([]);
});

it('checks with the seller at most once a day', function () {
    $fake = polar(new FakeCloudflare());
    $now  = 1_800_000_000;

    manager($fake, now: $now)->activate(KEY);
    manager($fake, now: $now)->refresh();

    expect($fake->requests)->toHaveCount(1);

    $now += LicenseManager::CHECK_EVERY + 1;
    manager($fake, now: $now)->refresh();

    expect($fake->requests)->toHaveCount(2);
});

it('activates a key from the environment on its first check', function () {
    $fake = polar(new FakeCloudflare());

    expect(manager($fake, key: KEY)->refresh())->toBe('active')
        ->and($fake->calls())->toBe(['POST /v1/customer-portal/license-keys/activate']);
});

it('reports a revoked key as inactive', function () {
    $fake = polar(new FakeCloudflare());
    $now  = 1_800_000_000;

    manager($fake, now: $now)->activate(KEY);

    $fake->on('POST', '/v1/customer-portal/license-keys/validate', ['type' => 'ResourceNotFound', 'detail' => 'Not found'], 404);

    expect(manager($fake, now: $now)->refresh(true))->toBe('inactive');
});

it('keeps the last answer while the seller is down, for two weeks', function () {
    $fake = polar(new FakeCloudflare());
    $now  = 1_800_000_000;

    manager($fake, now: $now)->activate(KEY);

    $fake->on('POST', '/v1/customer-portal/license-keys/validate', ['detail' => 'Bad gateway'], 502);

    $now += LicenseManager::CHECK_EVERY + 1;
    expect(manager($fake, now: $now)->refresh())->toBe('active');

    $now += LicenseManager::GRACE;
    expect(manager($fake, now: $now)->status())->toBe('unknown')
        ->and(manager($fake, now: $now)->info()['message'])->toContain('Not confirmed');
});

it('frees the activation when removed from a site', function () {
    $fake = polar(new FakeCloudflare());

    manager($fake)->activate(KEY);
    manager($fake)->deactivate();

    expect($fake->last()['body'])->toBe(['key' => KEY, 'organization_id' => ORG, 'activation_id' => 'act_1'])
        ->and(file_exists(licenseFile()))->toBeFalse()
        ->and(manager($fake)->status())->toBe('missing');
});

it('won\'t activate on a development site, or without a site URL', function (string $url, string $message) {
    expect(fn () => manager(polar(new FakeCloudflare()), $url)->activate(KEY))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['http://localhost', 'Development sites need no licence'],
    ['/', 'Set Kirby\'s `url` option'],
]);

it('treats an expired key as inactive', function () {
    expect(Polar::normalise(polarKey(['expires_at' => '2001-01-01T00:00:00Z']), 'act_1')['status'])->toBe('inactive');
});

it('shows the System view status without calling the seller', function () {
    $fake = fake(['licenseServer' => ['organization' => ORG]]);

    $license = App::plugin('ianhobbs/cloudflare-tools')->license();

    expect($license->status()->value())->toBe('missing')
        ->and($fake->requests)->toBe([]);
});

it('activates from the licence dialog, for admins', function () {
    $fake = polar(new FakeCloudflare());

    // the dialog reads the key from the submitted form
    bootKirby(
        ['licenseServer' => ['organization' => ORG], 'transport' => $fake],
        ['request' => ['method' => 'POST', 'body' => ['key' => KEY]]]
    );

    kirby()->impersonate('kirby');

    $dialog = kirby()->extensions('areas')['cloudflare-tools'][0](kirby())['dialogs']['cloudflare-tools.license'];

    expect($dialog['load']()['props']['fields'])->toHaveKeys(['info', 'key'])
        ->and($dialog['submit']()['message'])->toBe('Licence active for example.com')
        ->and($fake->last()['body']['label'])->toBe('example.com');
});

it('keeps non-admins out of the licence dialog', function () {
    fake();

    kirby()->impersonate('editor@example.com');

    kirby()->extensions('areas')['cloudflare-tools'][0](kirby())['dialogs']['cloudflare-tools.license']['load']();
})->throws(PermissionException::class, 'Only admins');

it('adds the licence to the Panel status, without the key', function () {
    polar(fake(['licenseServer' => ['organization' => ORG], 'license' => KEY]));

    $status = api('cloudflare-tools/status');

    expect($status['license'])->toMatchArray(['status' => 'active', 'source' => 'config', 'manage' => true])
        ->and(json_encode($status))->not->toContain(KEY);
});
