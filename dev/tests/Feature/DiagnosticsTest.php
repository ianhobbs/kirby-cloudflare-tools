<?php

use IanHobbs\CloudflareTools\Diagnostics;

const PROBE = 'https://example.com/favicon.ico';

function healthyCloudflare(array $probeHeaders): FakeCloudflare
{
    return fake(['probe' => PROBE])
        ->on('POST', 'zones/{zone}/purge_cache', ['result' => ['id' => TEST_ZONE]])
        ->on('GET', 'user/tokens/verify', ['result' => ['status' => 'active', 'expires_on' => null]])
        ->on('GET', 'zones/{zone}', ['result' => ['name' => 'example.com']])
        ->onUrl(PROBE, $probeHeaders);
}

it('passes when the token, zone and purge all work', function () {
    $fake = healthyCloudflare([
        ['CF-Cache-Status' => 'HIT', 'Age' => '500'],
        ['CF-Cache-Status' => 'HIT', 'Age' => '500'],
        ['CF-Cache-Status' => 'MISS'],
    ]);

    $report = Diagnostics::fromOptions()->check()->toArray();

    expect($report['ok'])->toBeTrue()
        ->and($report['lines'])->toBe([
            '✓ Token and zone found',
            '✓ Cloudflare accepted the purge of the probe file',
            '✓ Cache cleared: probe file went HIT → MISS',
            '✓ Token is active, no expiry date',
            '✓ Zone ID belongs to example.com',
        ])
        // only the probe file was purged
        ->and($fake->requests[2]['body'])->toBe(['files' => [PROBE]]);
});

it('fails when the zone ID belongs to another domain', function () {
    healthyCloudflare([['CF-Cache-Status' => 'MISS']])
        ->on('GET', 'zones/{zone}', ['result' => ['name' => 'other.org']]);

    $report = Diagnostics::fromOptions()->check()->toArray();

    expect($report['ok'])->toBeFalse()
        ->and($report['lines'])->toContain('✗ Zone ID belongs to other.org, not example.com');
});

it('explains a refused purge', function () {
    healthyCloudflare([['CF-Cache-Status' => 'HIT', 'Age' => '1']])
        ->on('POST', 'zones/{zone}/purge_cache', [
            'success' => false,
            'errors'  => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403);

    $report = Diagnostics::fromOptions()->check()->toArray();

    expect($report['ok'])->toBeFalse()
        ->and(implode("\n", $report['lines']))->toContain('Cloudflare refused the purge of the probe file: Authentication error (10000)');
});

it('notes, rather than fails, a probe Cloudflare was not caching', function () {
    healthyCloudflare([['CF-Cache-Status' => 'DYNAMIC']]);

    $report = Diagnostics::fromOptions()->check()->toArray();

    expect($report['ok'])->toBeTrue()
        ->and(implode("\n", $report['lines']))->toContain('– Cache not checked: Cloudflare was not caching the probe file');
});

it('stops at the first step when nothing is configured', function () {
    $fake = fake(['token' => null, 'zone' => null]);

    $report = Diagnostics::fromOptions()->check()->toArray();

    expect($report['ok'])->toBeFalse()
        ->and($report['lines'])->toHaveCount(1)
        ->and($fake->requests)->toBe([]);
});

it('uses the site favicon as the probe when none is set', function () {
    expect(Diagnostics::probeUrl())->toBe('https://example.com/favicon.ico');
});
