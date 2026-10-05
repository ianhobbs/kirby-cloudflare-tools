<?php

use IanHobbs\CloudflareTools\Api\Settings;

function settingsReply(): array
{
    return ['result' => [
        ['id' => 'development_mode', 'value' => 'on', 'editable' => true, 'time_remaining' => 3600],
        ['id' => 'cache_level', 'value' => 'aggressive', 'editable' => true],
        ['id' => 'browser_cache_ttl', 'value' => 14400, 'editable' => true],
        ['id' => 'always_online', 'value' => 'off', 'editable' => false],
        ['id' => 'security_level', 'value' => 'medium', 'editable' => true],
        ['id' => 'tls_1_3', 'value' => 'zrt', 'editable' => true],
        ['id' => 'not_in_catalogue', 'value' => 'x', 'editable' => true],
    ]];
}

it('lists every setting by ID', function () {
    fake()->on('GET', 'zones/{zone}/settings', settingsReply());

    $all = cloudflare()->settings()->all();

    expect($all)->toHaveKeys(['development_mode', 'not_in_catalogue'])
        ->and($all['development_mode'])->toMatchArray(['value' => 'on', 'editable' => true, 'time_remaining' => 3600]);
});

it('builds a group as Panel fields, with what Cloudflare reports', function () {
    fake()->on('GET', 'zones/{zone}/settings', settingsReply());

    $group = cloudflare()->settings()->group('cache');

    expect(array_keys($group['fields']))->toBe(['development_mode', 'cache_level', 'browser_cache_ttl', 'always_online'])
        ->and($group['value'])->toBe([
            'development_mode'  => true,
            'cache_level'       => 'aggressive',
            'browser_cache_ttl' => '14400',
            'always_online'     => false,
        ])
        // Cloudflare says always_online can't change on this zone
        ->and($group['fields']['always_online']['disabled'])->toBeTrue()
        ->and($group['fields']['cache_level']['disabled'])->toBeFalse()
        ->and($group['meta']['development_mode']['time_remaining'])->toBe(3600);
});

it('disables every field for a read-only role', function () {
    fake()->on('GET', 'zones/{zone}/settings', settingsReply());

    $fields = cloudflare()->settings()->group('cache', null, false)['fields'];

    expect(array_column($fields, 'disabled'))->each->toBeTrue();
});

it('converts between Cloudflare and Panel values', function () {
    expect(Settings::toPanel('development_mode', 'on'))->toBeTrue()
        ->and(Settings::toPanel('tls_1_3', 'zrt'))->toBeTrue()
        ->and(Settings::toPanel('tls_1_3', 'off'))->toBeFalse()
        ->and(Settings::fromPanel('always_use_https', true))->toBe('on')
        ->and(Settings::fromPanel('always_use_https', 'false'))->toBe('off')
        ->and(Settings::fromPanel('browser_cache_ttl', '3600'))->toBe(3600)
        ->and(Settings::fromPanel('min_tls_version', '1.2'))->toBe('1.2');
});

it('only accepts listed values', function () {
    Settings::fromPanel('ssl', 'very-strict');
})->throws(InvalidArgumentException::class, 'not a valid value');

it('only changes catalogued settings from the Panel', function () {
    Settings::fromPanel('origin_max_http_version', '2');
})->throws(InvalidArgumentException::class, 'cannot be changed from the Panel');

it('saves Panel changes one by one, reporting each refusal', function () {
    $fake = fake()
        ->on('PATCH', 'zones/{zone}/settings/development_mode', fn ($body) => ['result' => ['value' => $body['value']]])
        ->on('PATCH', 'zones/{zone}/settings/rocket_loader', [
            'success' => false,
            'errors'  => [['code' => 1015, 'message' => 'Not allowed on this plan']],
        ], 400);

    $result = cloudflare()->settings()->applyPanelChanges([
        'development_mode' => false,
        'rocket_loader'    => true,
        'ssl'              => 'nonsense',
    ]);

    expect($result['saved'])->toBe(['development_mode' => false])
        ->and($result['failed'])->toHaveKeys(['rocket_loader', 'ssl'])
        ->and($result['failed']['rocket_loader'])->toContain('Not allowed on this plan')
        // the invalid value never left the server
        ->and($fake->calls())->not->toContain('PATCH zones/' . TEST_ZONE . '/settings/ssl');
});

it('keeps every catalogued setting in a known group with valid options', function () {
    foreach (Settings::CATALOGUE as $id => $setting) {
        expect(Settings::GROUPS)->toHaveKey($setting['group'])
            ->and($setting['type'])->toBeIn(['toggle', 'select']);

        if ($setting['type'] === 'select') {
            expect($setting['options'])->not->toBeEmpty();
        }
    }
});
