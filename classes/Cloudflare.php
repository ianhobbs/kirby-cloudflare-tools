<?php

namespace IanHobbs\CloudflareTools;

use BadMethodCallException;
use Closure;
use IanHobbs\CloudflareTools\Api\Endpoint;
use Kirby\Cache\Cache;
use Kirby\Cms\App;

/**
 * The plugin's front door: one Cloudflare zone, and the API gates for it.
 *
 *   $cf = site()->cloudflare();            // credentials + zone from the options
 *   $cf->cache()->purgeEverything();
 *   $cf->settings()->set('development_mode', 'on');
 *   $cf->forDomain('example.com')->analytics()->traffic(7);
 *
 * Each gate is an Endpoint subclass, looked up by name. A site or another
 * plugin can register its own while trying out a new part of the API:
 *
 *   Cloudflare::extend('turnstile', MyTurnstile::class);
 *   site()->cloudflare()->turnstile()->widgets();
 *
 * or skip the class and call the API directly with request(), which fills in
 * {zone} and {account} in the path.
 *
 * @method Api\Analytics analytics()
 * @method Api\Cache     cache()
 * @method Api\Dns       dns()
 * @method Api\Security  security()
 * @method Api\Settings  settings()
 * @method Api\Ssl       ssl()
 * @method Api\Token     token()
 * @method Api\Zone      zone()
 */
final class Cloudflare
{
    /** @var array<string, class-string<Endpoint>> */
    private static array $endpoints = [
        'analytics' => Api\Analytics::class,
        'cache'     => Api\Cache::class,
        'dns'       => Api\Dns::class,
        'security'  => Api\Security::class,
        'settings'  => Api\Settings::class,
        'ssl'       => Api\Ssl::class,
        'token'     => Api\Token::class,
        'zone'      => Api\Zone::class,
    ];

    /** @var array<string, Endpoint> */
    private array $instances = [];

    private string|null $resolvedZone = null;

    public function __construct(
        private Client $client,
        private string|null $zone = null,
        private string|null $domain = null,
        private string|null $account = null,
        private int $cacheMinutes = 10
    ) {
        $this->zone    = Options::filled($zone);
        $this->domain  = Options::filled($domain);
        $this->account = Options::filled($account);
    }

    /**
     * Built from the plugin options; pass overrides for tests, or to serve
     * a second zone with other credentials:
     *   Cloudflare::fromOptions(['zone' => '…', 'token' => '…'])
     */
    public static function fromOptions(array $overrides = []): self
    {
        $config = [
            'token'     => Options::get('token'),
            'email'     => Options::get('email'),
            'key'       => Options::get('key'),
            'zone'      => Options::get('zone'),
            'domain'    => Options::get('domain'),
            'account'   => Options::get('account'),
            'endpoint'  => Options::get('endpoint'),
            'timeout'   => Options::get('timeout', 15),
            'cacheTtl'  => Options::get('cacheTtl', 10),
            // read raw: the transport is itself a callable, not a lazy option
            'transport' => option(Options::PREFIX . '.transport'),
        ];

        // empty overrides must not clobber the configured values
        $overrides = array_filter($overrides, fn ($value) => $value !== null && $value !== '');
        $config    = array_filter([...$config, ...$overrides], fn ($value) => $value !== null);

        foreach ((array)Options::get('endpoints', []) as $name => $class) {
            self::extend($name, $class);
        }

        return new self(
            new Client($config),
            $config['zone'] ?? null,
            $config['domain'] ?? null,
            $config['account'] ?? null,
            (int)($config['cacheTtl'] ?? 10)
        );
    }

    /** Registers (or replaces) an API gate */
    public static function extend(string $name, string $class): void
    {
        if (is_subclass_of($class, Endpoint::class) === false) {
            throw new \InvalidArgumentException('Cloudflare endpoint "' . $name . '" must extend ' . Endpoint::class);
        }

        self::$endpoints[$name] = $class;
    }

    /** @return array<string, class-string<Endpoint>> */
    public static function endpoints(): array
    {
        return self::$endpoints;
    }

    public function endpoint(string $name): Endpoint
    {
        $class = self::$endpoints[$name] ?? throw new BadMethodCallException('No Cloudflare endpoint called "' . $name . '"');

        return $this->instances[$name] ??= new $class($this);
    }

    public function __call(string $name, array $arguments): Endpoint
    {
        return $this->endpoint($name);
    }

    public function client(): Client
    {
        return $this->client;
    }

    /** Credentials, and a zone ID or a domain to look one up from */
    public function isConfigured(): bool
    {
        return $this->client->hasCredentials() && ($this->zone !== null || $this->domain !== null);
    }

    /** What is missing, in words for the Panel; empty when configured */
    public function missing(): array
    {
        $missing = [];

        if ($this->client->hasCredentials() === false) {
            $missing[] = 'CLOUDFLARE_API_TOKEN';
        }

        if ($this->zone === null && $this->domain === null) {
            $missing[] = 'CLOUDFLARE_ZONE_ID';
        }

        return $missing;
    }

    /**
     * The zone ID: the configured one, or looked up from the domain with
     * the token (needs Zone › Zone › Read) and cached
     */
    public function zoneId(): string
    {
        if ($this->resolvedZone !== null) {
            return $this->resolvedZone;
        }

        if ($this->zone !== null) {
            if (preg_match('/^[a-f0-9]{32}$/', $this->zone) !== 1) {
                throw new ApiException('CLOUDFLARE_ZONE_ID should be the 32-character Zone ID from the domain\'s Overview page in Cloudflare');
            }

            return $this->resolvedZone = $this->zone;
        }

        if ($this->domain === null) {
            throw new ApiException('No Cloudflare zone configured: set CLOUDFLARE_ZONE_ID');
        }

        $domain = Api\Zone::normaliseDomain($this->domain);

        return $this->resolvedZone = $this->cached(
            'zone-id.' . $domain,
            fn () => Api\Zone::resolve($this->client, $domain),
            60 * 24
        );
    }

    /** The account ID: configured, or read from the zone (no extra permission) */
    public function accountId(): string|null
    {
        return $this->account ??= Options::filled($this->zone()->details()['account']['id'] ?? null);
    }

    public function domain(): string|null
    {
        return $this->domain;
    }

    /** The same credentials, another zone */
    public function forZone(string $zone): self
    {
        return new self($this->client, $zone, null, $this->account, $this->cacheMinutes);
    }

    /** The same credentials, the zone that serves this domain */
    public function forDomain(string $domain): self
    {
        return new self($this->client, null, $domain, $this->account, $this->cacheMinutes);
    }

    /**
     * Any REST call, for trying out API gates the plugin has no class for yet.
     * `{zone}` and `{account}` in the path are filled in:
     *   $cf->request('GET', 'zones/{zone}/settings/http3')->result();
     */
    public function request(string $method, string $path, array $query = [], array|null $body = null): Response
    {
        if (str_contains($path, '{zone}')) {
            $path = str_replace('{zone}', rawurlencode($this->zoneId()), $path);
        }

        if (str_contains($path, '{account}')) {
            $account = $this->accountId() ?? throw new ApiException('No Cloudflare account ID: set CLOUDFLARE_ACCOUNT_ID');
            $path    = str_replace('{account}', rawurlencode($account), $path);
        }

        return $this->client->request($method, $path, $query, $body);
    }

    /**
     * Caches a value in the plugin's Kirby cache, per zone, for $minutes or
     * the `cacheTtl` option. A `cacheTtl` of 0 turns all of it off.
     */
    public function cached(string $key, Closure $callback, int|null $minutes = null): mixed
    {
        $minutes ??= $this->cacheMinutes;
        $cache     = $this->cache();

        if ($this->cacheMinutes <= 0 || $minutes <= 0 || $cache === null) {
            return $callback();
        }

        $key = $this->cacheKey($key);
        $hit = $cache->get($key);

        if ($hit !== null) {
            return $hit;
        }

        $value = $callback();
        $cache->set($key, $value, $minutes);

        return $value;
    }

    /** Keeps a value in the plugin cache, per zone */
    public function store(string $key, mixed $value, int $minutes): void
    {
        $this->cache()?->set($this->cacheKey($key), $value, $minutes);
    }

    /** A value kept with store() or cached(), or null */
    public function stored(string $key): mixed
    {
        return $this->cache()?->get($this->cacheKey($key));
    }

    /** Drops one cached value, e.g. after changing what it was read from */
    public function forget(string $key): void
    {
        $this->cache()?->remove($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        // the zone-id lookup itself must not need the zone
        $scope = $this->resolvedZone ?? $this->zone ?? $this->domain ?? 'none';

        return $scope . '.' . $key;
    }

    private function cache(): Cache|null
    {
        $kirby = App::instance(null, true);

        return $kirby?->cache(Options::PREFIX);
    }
}
