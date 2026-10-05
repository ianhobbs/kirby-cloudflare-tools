<?php

use IanHobbs\CloudflareTools\Api\Analytics;

function trafficFixture(): array
{
    return [
        ['dimensions' => ['date' => '2026-06-17'], 'uniq' => ['uniques' => 30], 'sum' => [
            'requests' => 100, 'pageViews' => 40, 'bytes' => 1000, 'threats' => 2,
            'cachedRequests' => 40, 'cachedBytes' => 500,
            'responseStatusMap' => [
                ['edgeResponseStatus' => 200, 'requests' => 80],
                ['edgeResponseStatus' => 404, 'requests' => 15],
                ['edgeResponseStatus' => 500, 'requests' => 5],
            ],
            'countryMap' => [
                ['clientCountryName' => 'AU', 'requests' => 60, 'bytes' => 600, 'threats' => 1],
                ['clientCountryName' => 'US', 'requests' => 40, 'bytes' => 400, 'threats' => 1],
            ],
        ]],
        ['dimensions' => ['date' => '2026-06-18'], 'uniq' => ['uniques' => 50], 'sum' => [
            'requests' => 200, 'pageViews' => 60, 'bytes' => 2000, 'threats' => 3,
            'cachedRequests' => 60, 'cachedBytes' => 1500,
            'responseStatusMap' => [
                ['edgeResponseStatus' => 200, 'requests' => 170],
                ['edgeResponseStatus' => 404, 'requests' => 25],
                ['edgeResponseStatus' => 503, 'requests' => 5],
            ],
            'countryMap' => [
                ['clientCountryName' => 'AU', 'requests' => 100, 'bytes' => 1000, 'threats' => 1],
                ['clientCountryName' => 'US', 'requests' => 60, 'bytes' => 600, 'threats' => 1],
                ['clientCountryName' => 'FR', 'requests' => 40, 'bytes' => 400, 'threats' => 0],
            ],
        ]],
    ];
}

it('sums the daily groups into totals and a series', function () {
    $n = Analytics::normaliseGroups(trafficFixture());

    expect($n['totals'])->toMatchArray([
        'requests' => 300, 'pageViews' => 100, 'bytes' => 3000, 'threats' => 5, 'uniques' => 80, 'cachedRequests' => 100,
    ])
        ->and($n['series'])->toHaveCount(2)
        ->and($n['series'][0]['date'])->toBe('2026-06-17')
        ->and($n['series'][1]['uniques'])->toBe(50);
});

it('works out the cache hit and error rates', function () {
    $n = Analytics::normaliseGroups(trafficFixture());

    expect($n['cacheHitRatio'])->toBe(33.3)
        ->and($n['status'])->toMatchArray(['2xx' => 250, '4xx' => 40, '5xx' => 10])
        ->and($n['errorRate'])->toBe(16.7);
});

it('merges and ranks countries across days', function () {
    $countries = Analytics::normaliseGroups(trafficFixture())['topCountries'];

    expect(array_column($countries, 'country'))->toBe(['AU', 'US', 'FR'])
        ->and($countries[0]['requests'])->toBe(160);
});

it('copes with empty and partial data', function () {
    expect(Analytics::normaliseGroups([])['totals']['requests'])->toBe(0)
        ->and(Analytics::normaliseGroups([])['cacheHitRatio'])->toBe(0.0)
        ->and(Analytics::normaliseGroups([['dimensions' => ['date' => 'x']]])['series'][0]['requests'])->toBe(0);
});

it('inlines validated literals instead of GraphQL variables', function () {
    $query = Analytics::trafficQuery(TEST_ZONE, '2026-06-01', '2026-06-07');

    expect($query)->toContain(TEST_ZONE)
        ->toContain('2026-06-01')
        ->toContain('httpRequests1dGroups')
        ->not->toContain('$');
});

it('refuses anything else in the query', function () {
    Analytics::trafficQuery('" } } mutation {', '2026-06-01', '2026-06-07');
})->throws(InvalidArgumentException::class);

it('fetches traffic for the zone, clamped to 30 days', function () {
    $fake = fake()->on('POST', 'graphql', ['data' => ['viewer' => ['zones' => [['httpRequests1dGroups' => trafficFixture()]]]]]);

    $traffic = cloudflare()->analytics()->traffic(90);

    expect($traffic['range']['days'])->toBe(30)
        ->and($traffic['totals']['requests'])->toBe(300)
        ->and($fake->last()['body']['query'])->toContain(TEST_ZONE);
});

it('formats bytes', function () {
    expect(Analytics::humanBytes(0))->toBe('0 B')
        ->and(Analytics::humanBytes(1536))->toBe('1.5 KB')
        ->and(Analytics::humanBytes(1073741824))->toBe('1.0 GB');
});
