<?php

namespace IanHobbs\CloudflareTools\Api;

use InvalidArgumentException;

/**
 * Cache purging and Tiered Cache.
 *
 * Every purge method is available on every plan, Free included, since April
 * 2025. Free accounts may send 5 tag/host/prefix/everything purges a minute
 * (a bucket of 25), shared by all Free zones on the account.
 *
 * Token permissions: Zone › Cache Purge › Purge; Zone › Zone Settings ›
 * Read/Edit for Tiered Cache.
 */
final class Cache extends Endpoint
{
    public const TYPES = ['everything', 'urls', 'tags', 'hosts', 'prefixes'];

    // Cloudflare takes up to 100 items per request; URLs go in smaller
    // batches, as single-file purges have had lower per-request caps on Free
    public const URLS_PER_REQUEST  = 30;
    public const ITEMS_PER_REQUEST = 100;

    /** Purges by type; returns how many items were sent (1 for everything) */
    public function purge(string $type, array|string $items = []): int
    {
        return match ($type) {
            'everything' => $this->purgeEverything(),
            'urls'       => $this->purgeUrls($items),
            'tags'       => $this->purgeTags($items),
            'hosts'      => $this->purgeHosts($items),
            'prefixes'   => $this->purgePrefixes($items),
            default      => throw new InvalidArgumentException('Unknown purge type "' . $type . '", use one of: ' . implode(', ', self::TYPES)),
        };
    }

    public function purgeEverything(): int
    {
        $this->send(['purge_everything' => true]);

        return 1;
    }

    /** Full URLs, scheme included, exactly as visitors request them */
    public function purgeUrls(array|string $urls): int
    {
        $urls = self::list($urls);

        foreach ($urls as $url) {
            if (preg_match('~^https?://~i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Not a full URL: "' . $url . '"');
            }
        }

        return $this->sendInChunks('files', $urls, self::URLS_PER_REQUEST);
    }

    /** Cache-Tag header values your origin sends */
    public function purgeTags(array|string $tags): int
    {
        $tags = self::list($tags);

        foreach ($tags as $tag) {
            if (preg_match('/^[\x21-\x7E]{1,1024}$/', $tag) !== 1) {
                throw new InvalidArgumentException('Cache tags are printable ASCII without spaces: "' . $tag . '"');
            }
        }

        return $this->sendInChunks('tags', $tags, self::ITEMS_PER_REQUEST);
    }

    /** Hostnames, e.g. "www.example.com" */
    public function purgeHosts(array|string $hosts): int
    {
        $hosts = array_map(
            fn (string $host) => Zone::normaliseDomain($host),
            self::list($hosts)
        );

        return $this->sendInChunks('hosts', array_values(array_unique($hosts)), self::ITEMS_PER_REQUEST);
    }

    /** Host plus path, without the scheme: "www.example.com/blog" */
    public function purgePrefixes(array|string $prefixes): int
    {
        $prefixes = array_map(function (string $prefix) {
            $prefix = preg_replace('~^https?://~i', '', $prefix);

            if (str_contains($prefix, '/') === false) {
                throw new InvalidArgumentException('A prefix is a host and a path, e.g. "www.example.com/blog": "' . $prefix . '"');
            }

            return $prefix;
        }, self::list($prefixes));

        return $this->sendInChunks('prefixes', array_values(array_unique($prefixes)), self::ITEMS_PER_REQUEST);
    }

    /** Tiered Cache routes cache misses through upper-tier data centres */
    public function tieredCaching(): bool
    {
        return ($this->client()->get($this->zonePath('argo/tiered_caching'))->result()['value'] ?? 'off') === 'on';
    }

    public function setTieredCaching(bool $on): bool
    {
        $value = $this->client()->patch($this->zonePath('argo/tiered_caching'), ['value' => $on ? 'on' : 'off'])->result()['value'] ?? null;

        return $value === 'on';
    }

    /** Smart Topology picks the upper tier closest to the origin (Free plans included) */
    public function smartTopology(): bool
    {
        return ($this->client()->get($this->zonePath('cache/tiered_cache_smart_topology_enable'))->result()['value'] ?? 'off') === 'on';
    }

    public function setSmartTopology(bool $on): bool
    {
        $value = $this->client()->patch($this->zonePath('cache/tiered_cache_smart_topology_enable'), ['value' => $on ? 'on' : 'off'])->result()['value'] ?? null;

        return $value === 'on';
    }

    /**
     * Splits textarea input into a clean list: one item per line or comma,
     * trimmed, blanks and duplicates dropped
     */
    public static function list(array|string $items): array
    {
        if (is_string($items) === true) {
            $items = preg_split('/[\r\n,]+/', $items) ?: [];
        }

        $items = array_map(fn ($item) => trim((string)$item), $items);

        return array_values(array_unique(array_filter($items, fn ($item) => $item !== '')));
    }

    private function sendInChunks(string $key, array $items, int $size): int
    {
        if ($items === []) {
            throw new InvalidArgumentException('Nothing to purge');
        }

        foreach (array_chunk($items, $size) as $chunk) {
            $this->send([$key => $chunk]);
        }

        return count($items);
    }

    private function send(array $body): void
    {
        $this->client()->post($this->zonePath('purge_cache'), $body)->result();
    }
}
