<?php

use IanHobbs\CloudflareTools\Api\Security;

it('works out what an access rule targets', function (string $value, string $target) {
    expect(Security::detectTarget($value))->toBe($target);
})->with([
    ['203.0.113.7', 'ip'],
    ['2001:db8::1', 'ip6'],
    ['203.0.113.0/24', 'ip_range'],
    ['2001:db8::/48', 'ip_range'],
    ['nz', 'country'],
    ['T1', 'country'],
    ['AS13335', 'asn'],
    ['13335', 'asn'],
]);

it('rejects values no rule can target', function () {
    Security::detectTarget('example.com');
})->throws(InvalidArgumentException::class);

it('adds an access rule', function () {
    $fake = fake()->on('POST', 'zones/{zone}/firewall/access_rules/rules', fn ($body) => ['result' => [
        'id'            => 'r1',
        'mode'          => $body['mode'],
        'configuration' => $body['configuration'],
        'notes'         => $body['notes'],
        'scope'         => ['type' => 'zone'],
    ]]);

    $rule = cloudflare()->security()->addAccessRule('block', ' nz ', null, 'spam');

    expect($fake->last()['body'])->toBe([
        'mode'          => 'block',
        'configuration' => ['target' => 'country', 'value' => 'NZ'],
        'notes'         => 'spam',
    ])->and($rule)->toMatchArray(['id' => 'r1', 'target' => 'country', 'value' => 'NZ', 'scope' => 'zone']);
});

it('refuses unknown access rule actions', function () {
    cloudflare()->security()->addAccessRule('nuke', '203.0.113.7');
})->throws(InvalidArgumentException::class, 'Unknown action');

it('sends the writable bot fields back with a change, and only those', function () {
    $fake = fake()
        ->on('GET', 'zones/{zone}/bot_management', ['result' => [
            'fight_mode'               => false,
            'enable_js'                => true,
            'ai_bots_protection'       => 'disabled',
            'using_latest_model'       => true,
            'stale_zone_configuration' => [],
        ]])
        ->on('PUT', 'zones/{zone}/bot_management', fn ($body) => ['result' => $body]);

    cloudflare()->security()->setBotFightMode(true);

    expect($fake->last()['body'])->toBe([
        'fight_mode'         => true,
        'enable_js'          => true,
        'ai_bots_protection' => 'disabled',
    ]);
});

it('remembers the security level it replaces under attack, and restores it', function () {
    $level = 'high';
    $fake  = fake()
        ->on('GET', 'zones/{zone}/settings/security_level', function () use (&$level) {
            return ['result' => ['value' => $level]];
        })
        ->on('PATCH', 'zones/{zone}/settings/security_level', function ($body) use (&$level) {
            $level = $body['value'];
            return ['result' => ['value' => $level]];
        });

    expect(cloudflare()->security()->underAttack(true))->toBe('under_attack')
        ->and(cloudflare()->security()->isUnderAttack())->toBeTrue()
        ->and(cloudflare()->security()->underAttack(false))->toBe('high');
});

it('falls back to medium when it has nothing to restore', function () {
    fake()->on('PATCH', 'zones/{zone}/settings/security_level', fn ($body) => ['result' => ['value' => $body['value']]]);

    cloudflare()->forget('security-level-before-attack');

    expect(cloudflare()->security()->underAttack(false))->toBe('medium');
});
