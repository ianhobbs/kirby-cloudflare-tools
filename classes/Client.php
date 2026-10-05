<?php

namespace IanHobbs\CloudflareTools;

use Closure;
use Kirby\Http\Remote;
use Throwable;

/**
 * Cloudflare HTTP client: transport and authentication only.
 *
 * Talks to the REST API and its GraphQL endpoint. Prefer a scoped API token
 * (Bearer); the legacy email + Global API Key pair still works. Credentials
 * only ever go to the configured API base — Diagnostics fetches the probe
 * file through the bare transport, without them.
 *
 * The transport is injectable so tests can answer for Cloudflare:
 *   fn (string $method, string $url, array $headers, ?string $body, int $timeout): array{int, string, array}
 */
final class Client
{
    public const ENDPOINT = 'https://api.cloudflare.com/client/v4';

    private string $base;
    private string|null $token;
    private string|null $email;
    private string|null $key;
    private int $timeout;
    private Closure $transport;

    public function __construct(array $config = [])
    {
        $this->base      = rtrim(Options::filled($config['endpoint'] ?? null) ?? self::ENDPOINT, '/');
        $this->token     = Options::filled($config['token'] ?? null);
        $this->email     = Options::filled($config['email'] ?? null);
        $this->key       = Options::filled($config['key'] ?? null);
        $this->timeout   = max(1, (int)($config['timeout'] ?? 15));
        $this->transport = isset($config['transport'])
            ? Closure::fromCallable($config['transport'])
            : self::remote(...);
    }

    /** Whether there is a token, or a complete legacy email + key pair */
    public function hasCredentials(): bool
    {
        return $this->token !== null || ($this->email !== null && $this->key !== null);
    }

    /** Whether the legacy Global API Key is in use (no token to verify) */
    public function usesLegacyKey(): bool
    {
        return $this->token === null && $this->hasCredentials();
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, [], $body);
    }

    public function put(string $path, array $body = []): Response
    {
        return $this->request('PUT', $path, [], $body);
    }

    public function patch(string $path, array $body = []): Response
    {
        return $this->request('PATCH', $path, [], $body);
    }

    public function delete(string $path, array $body = []): Response
    {
        return $this->request('DELETE', $path, [], $body === [] ? null : $body);
    }

    /**
     * Any REST call. $path is relative to the API base, e.g. "zones".
     * Returns the response whatever its status; throws only when the request
     * could not be made at all (no credentials, no connection).
     */
    public function request(string $method, string $path, array $query = [], array|null $body = null): Response
    {
        if ($this->hasCredentials() === false) {
            throw new ApiException('Cloudflare credentials missing: set CLOUDFLARE_API_TOKEN (recommended), or CLOUDFLARE_API_EMAIL and CLOUDFLARE_API_KEY');
        }

        $url = $this->base . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = $this->authHeaders() + ['Accept' => 'application/json'];
        $payload = null;

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            // an empty body must stay an object: Cloudflare rejects `[]`
            $payload = json_encode($body === [] ? (object)[] : $body, JSON_UNESCAPED_SLASHES);
        }

        try {
            [$status, $raw, $responseHeaders] = ($this->transport)(strtoupper($method), $url, $headers, $payload, $this->timeout) + [2 => []];
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApiException('Cloudflare request failed: ' . $e->getMessage(), 0, null, $e);
        }

        return Response::from((int)$status, (string)$raw, (array)$responseHeaders);
    }

    /**
     * POSTs a GraphQL document and returns its `data`.
     *
     * Cloudflare's Analytics schema names its scalars in lowercase (`string`,
     * `uint64`), which breaks typed GraphQL variables — so callers validate
     * their inputs and inline them as literals instead (see Analytics).
     */
    public function graphql(string $query): array
    {
        $response = $this->request('POST', 'graphql', [], ['query' => $query]);
        $errors   = $response->errors();

        if ($errors !== []) {
            throw new ApiException('Cloudflare GraphQL error: ' . ($errors[0]['message'] ?? 'unknown error'), $response->status);
        }

        if ($response->ok() === false) {
            throw ApiException::fromResponse($response);
        }

        return $response->json['data'] ?? [];
    }

    /**
     * A plain GET to any URL, without credentials — for the probe file that
     * shows whether a purge reached the edge
     *
     * @return array<string, string> response headers, names lowercased
     */
    public function headersOf(string $url, int $timeout = 10): array
    {
        [, , $headers] = ($this->transport)('GET', $url, [], null, $timeout) + [2 => []];

        return array_change_key_case((array)$headers);
    }

    /** Bearer token preferred, else the legacy email + Global API Key pair */
    private function authHeaders(): array
    {
        if ($this->token !== null) {
            return ['Authorization' => 'Bearer ' . $this->token];
        }

        return [
            'X-Auth-Email' => (string)$this->email,
            'X-Auth-Key'   => (string)$this->key,
        ];
    }

    /**
     * The default transport, Kirby's own cURL wrapper
     *
     * @return array{int, string, array} status, body and response headers
     */
    public static function remote(string $method, string $url, array $headers, string|null $body, int $timeout): array
    {
        $options = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        if ($body !== null) {
            // a string: Remote form-encodes arrays
            $options['data'] = $body;
        }

        $response = Remote::request($url, $options);

        return [$response->code(), (string)$response->content(), $response->headers()];
    }
}
