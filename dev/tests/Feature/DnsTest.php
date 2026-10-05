<?php

use IanHobbs\CloudflareTools\Api\Dns;

it('fetches every page of records, sorted', function () {
    $fake = fake()->on('GET', 'zones/{zone}/dns_records', fn ($body, $query) => listReply(
        $query['page'] === '1'
            ? [['id' => '1', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx.example.com', 'priority' => 10]]
            : [['id' => '2', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.7', 'proxied' => true, 'proxiable' => true]],
        (int)$query['page'],
        2
    ));

    $records = cloudflare()->dns()->records();

    expect($fake->requests)->toHaveCount(2)
        ->and(array_column($records, 'type'))->toBe(['A', 'MX'])
        ->and($records[0])->toMatchArray(['proxied' => true, 'ttl' => 1]);
});

it('keeps only the fields it lets you set, typed', function () {
    expect(Dns::clean([
        'type'     => 'a',
        'name'     => ' www ',
        'content'  => '203.0.113.7',
        'ttl'      => '30',
        'proxied'  => 'true',
        'id'       => 'injected',
        'zone_id'  => 'injected',
    ], true))->toBe([
        'type'    => 'A',
        'name'    => 'www',
        'content' => '203.0.113.7',
        'ttl'     => 60,
        'proxied' => true,
    ]);
});

it('drops the proxy flag for records that cannot be proxied, and gives MX a priority', function () {
    expect(Dns::clean(['type' => 'MX', 'name' => '@', 'content' => 'mx.example.com', 'proxied' => true], true))
        ->toBe(['type' => 'MX', 'name' => '@', 'content' => 'mx.example.com', 'priority' => 10]);
});

it('needs a type, name and content for a new record', function () {
    Dns::clean(['type' => 'A', 'name' => 'www'], true);
})->throws(InvalidArgumentException::class, 'needs a content');

it('refuses unknown record types', function () {
    Dns::clean(['type' => 'SPF'], false);
})->throws(InvalidArgumentException::class, 'Unsupported DNS record type');

it('switches the proxy with a PATCH of that field alone', function () {
    $fake = fake()->on('PATCH', 'zones/{zone}/dns_records/abc', fn ($body) => ['result' => ['id' => 'abc', 'type' => 'A'] + $body]);

    $record = cloudflare()->dns()->setProxied('abc', false);

    expect($fake->last()['body'])->toBe(['proxied' => false])
        ->and($record['proxied'])->toBeFalse();
});
