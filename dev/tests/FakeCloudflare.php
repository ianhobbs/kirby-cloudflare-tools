<?php

/**
 * Answers for Cloudflare in tests. Plugged in as the client's transport, it
 * records every request and replies from the routes registered with on():
 *
 *   $fake->on('GET', 'zones/{zone}', ['result' => ['name' => 'example.com']]);
 *   $fake->on('POST', 'zones/{zone}/purge_cache', fn ($body) => [...]);
 *
 * `{zone}` stands for TEST_ZONE. A reply without `success` gets success: true
 * for a 2xx. Unrouted requests answer 404 with a Cloudflare-shaped error.
 * Requests to other hosts (the probe file) go to onUrl() routes.
 */
final class FakeCloudflare
{
    /** @var array<int, array{method: string, url: string, path: string, query: array, headers: array, body: mixed}> */
    public array $requests = [];

    private array $routes = [];
    private array $urls   = [];

    public function on(string $method, string $path, array|Closure $reply, int $status = 200): self
    {
        $this->routes[strtoupper($method) . ' ' . str_replace('{zone}', TEST_ZONE, $path)] = [$reply, $status];

        return $this;
    }

    /**
     * Replies for a plain URL (the probe file); a list of header sets is
     * served one per request, the last one repeating
     */
    public function onUrl(string $url, array $headerSets): self
    {
        $this->urls[$url] = $headerSets;

        return $this;
    }

    public function __invoke(string $method, string $url, array $headers, string|null $body, int $timeout): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        $path = preg_replace('~^/client/v4/~', '', $parts['path'] ?? '');

        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'path'    => $path,
            'query'   => $query,
            'headers' => $headers,
            'body'    => $body === null ? null : json_decode($body, true),
            'raw'     => $body,
        ];

        if (isset($this->urls[$url]) === true) {
            $set = count($this->urls[$url]) > 1 ? array_shift($this->urls[$url]) : $this->urls[$url][0];

            return [200, '', $set];
        }

        $route = $this->routes[$method . ' ' . $path] ?? null;

        if ($route === null) {
            return [404, json_encode([
                'success' => false,
                'errors'  => [['code' => 7003, 'message' => 'Could not route to ' . $path]],
            ]), []];
        }

        [$reply, $status] = $route;

        if ($reply instanceof Closure) {
            $reply = $reply(end($this->requests)['body'], $query);
        }

        if ($status >= 200 && $status < 300) {
            $reply += ['success' => true, 'errors' => []];
        }

        return [$status, json_encode($reply), []];
    }

    /** The requests made, as "METHOD path" strings */
    public function calls(): array
    {
        return array_map(fn ($request) => $request['method'] . ' ' . $request['path'], $this->requests);
    }

    public function last(): array
    {
        return end($this->requests) ?: [];
    }
}
