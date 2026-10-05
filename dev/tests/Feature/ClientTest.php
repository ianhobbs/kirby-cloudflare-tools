<?php

use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Client;
use IanHobbs\CloudflareTools\Response;

it('sends a bearer token and JSON', function () {
    $fake   = new FakeCloudflare();
    $client = new Client(['token' => 'abc', 'transport' => $fake]);

    $fake->on('PATCH', 'zones/x/settings/ssl', ['result' => ['value' => 'strict']]);
    $client->patch('zones/x/settings/ssl', ['value' => 'strict']);

    $request = $fake->last();

    expect($request['headers']['Authorization'])->toBe('Bearer abc')
        ->and($request['headers']['Content-Type'])->toBe('application/json')
        ->and($request['body'])->toBe(['value' => 'strict'])
        ->and($request['url'])->toBe('https://api.cloudflare.com/client/v4/zones/x/settings/ssl');
});

it('falls back to the legacy email and Global API Key', function () {
    $fake   = new FakeCloudflare();
    $client = new Client(['email' => 'a@b.c', 'key' => 'k', 'transport' => $fake]);

    $client->get('zones');

    expect($fake->last()['headers'])->toMatchArray(['X-Auth-Email' => 'a@b.c', 'X-Auth-Key' => 'k'])
        ->and($fake->last()['headers'])->not->toHaveKey('Authorization')
        ->and($client->usesLegacyKey())->toBeTrue();
});

it('needs a token, or both halves of the legacy pair', function (array $config, bool $expected) {
    expect((new Client($config))->hasCredentials())->toBe($expected);
})->with([
    'none'          => [[], false],
    'token'         => [['token' => 'abc'], true],
    'blank token'   => [['token' => '  '], false],
    'email + key'   => [['email' => 'a@b.c', 'key' => 'k'], true],
    'email only'    => [['email' => 'a@b.c'], false],
]);

it('refuses to send a request without credentials', function () {
    (new Client(['transport' => new FakeCloudflare()]))->get('zones');
})->throws(ApiException::class, 'credentials missing');

it('sends an empty body as a JSON object, not an array', function () {
    $fake = new FakeCloudflare();
    (new Client(['token' => 'abc', 'transport' => $fake]))->post('zones/x/purge_cache');

    expect($fake->last()['raw'])->toBe('{}');
});

it('builds the query string', function () {
    $fake = new FakeCloudflare();
    (new Client(['token' => 'abc', 'transport' => $fake]))->get('zones', ['name' => 'example.com', 'status' => 'active']);

    expect($fake->last()['query'])->toBe(['name' => 'example.com', 'status' => 'active']);
});

it('wraps a failed connection in an ApiException', function () {
    $client = new Client([
        'token'     => 'abc',
        'transport' => fn () => throw new RuntimeException('Could not resolve host'),
    ]);

    $client->get('zones');
})->throws(ApiException::class, 'Cloudflare request failed: Could not resolve host');

it('never sends credentials to the probe URL', function () {
    $fake = new FakeCloudflare();
    $fake->onUrl('https://example.com/favicon.ico', [['CF-Cache-Status' => 'HIT', 'Age' => '12']]);

    $headers = (new Client(['token' => 'secret', 'transport' => $fake]))->headersOf('https://example.com/favicon.ico');

    expect($fake->last()['headers'])->toBe([])
        ->and($headers)->toBe(['cf-cache-status' => 'HIT', 'age' => '12']);
});

it('reads success, result and pagination from a response', function () {
    $response = Response::from(200, json_encode([
        'success'     => true,
        'result'      => [['id' => 1]],
        'result_info' => ['total_pages' => 3],
    ]));

    expect($response->ok())->toBeTrue()
        ->and($response->result())->toBe([['id' => 1]])
        ->and($response->info()['total_pages'])->toBe(3);
});

it('throws Cloudflare\'s own error, with a hint, from result()', function () {
    Response::from(400, json_encode([
        'success' => false,
        'errors'  => [['code' => 7003, 'message' => 'Could not route to /zones/nope']],
    ]))->result();
})->throws(ApiException::class, 'Could not route to /zones/nope (7003), check CLOUDFLARE_ZONE_ID');

it('explains the common errors', function (int $status, array $json, string $expected) {
    expect(ApiException::describe($status, $json))->toContain($expected);
})->with([
    'bad token'      => [400, ['errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 'token not recognised'],
    'malformed'      => [400, ['errors' => [['code' => 6003, 'message' => 'Invalid request headers']]], 'stray space'],
    'auth error'     => [403, ['errors' => [['code' => 10000, 'message' => 'Authentication error']]], 'lacks the permission'],
    'IP filtering'   => [403, ['errors' => [['code' => 9109, 'message' => 'Cannot use the access token from location: 1.2.3.4']]], "allow this server's IP"],
    'rate limited'   => [429, [], 'rate limiting'],
    'HTML error'     => [502, [], 'HTTP 502'],
]);

it('treats a GraphQL error as a failure', function () {
    $fake = (new FakeCloudflare())->on('POST', 'graphql', [
        'data'   => null,
        'errors' => [['message' => 'zone not authorized']],
    ]);

    (new Client(['token' => 'abc', 'transport' => $fake]))->graphql('query { viewer { zones { zoneTag } } }');
})->throws(ApiException::class, 'Cloudflare GraphQL error: zone not authorized');
