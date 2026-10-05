<?php

namespace IanHobbs\CloudflareTools;

use Throwable;

/**
 * Checks whether the token works, the zone ID is right, and a purge really
 * clears the edge — as a list of pass/fail lines for the CLI, Janitor and
 * the Panel's Diagnostics tab.
 *
 * The probe is a small file Cloudflare caches (a favicon is ideal). It is
 * fetched without credentials, so the token never leaves for the site.
 */
final class Diagnostics
{
    /** @var string[] */
    public array $lines = [];
    public bool $ok = true;
    private float $purgedAt = 0.0;

    public function __construct(
        private Cloudflare $cloudflare,
        private string|null $probe = null
    ) {
        $this->probe = Options::filled($probe);
    }

    public static function fromOptions(Cloudflare|null $cloudflare = null): self
    {
        return new self($cloudflare ?? Cloudflare::fromOptions(), self::probeUrl());
    }

    /** The `probe` option, else the site's favicon when the site URL is absolute */
    public static function probeUrl(): string|null
    {
        $probe = Options::filled(Options::get('probe'));

        if ($probe !== null) {
            return $probe;
        }

        $url = rtrim((string)site()->url(), '/');

        return preg_match('~^https?://[^/]+~', $url) === 1 ? $url . '/favicon.ico' : null;
    }

    /** Records a pass or fail line; one fail fails the whole run */
    public function step(bool $ok, string $line): bool
    {
        $this->lines[] = ($ok ? '✓ ' : '✗ ') . $line;
        $this->ok      = $this->ok && $ok;

        return $ok;
    }

    /** Records a line that neither passes nor fails */
    public function note(string $line): void
    {
        $this->lines[] = '– ' . $line;
    }

    public function configured(): bool
    {
        $missing = $this->cloudflare->missing();

        if ($missing !== []) {
            return $this->step(false, implode(' and ', $missing) . ' missing: see the README, "Credentials"');
        }

        return $this->step(true, 'Token and zone found');
    }

    /**
     * Is the token valid, enabled and unexpired? Says nothing about its
     * permissions. After a successful purge a failed check is only a note,
     * since the purge already proved the token works.
     */
    public function verifyToken(bool $purged = false): void
    {
        if ($this->cloudflare->client()->usesLegacyKey()) {
            $this->note('Using the legacy Global API Key; a scoped API token is safer');
            return;
        }

        $result = $this->cloudflare->token()->verify();
        $status = $result['status'];

        if ($status === 'active') {
            $this->step(true, 'Token is active' . ($result['expires'] ? ', expires ' . $result['expires'] : ', no expiry date'));
        } elseif ($purged) {
            $this->note('Token status not checked (' . $result['response']->error() . '), but the purge worked');
        } else {
            $this->step(false, $status ? 'Token is ' . $status : 'Token check failed: ' . $result['response']->error());
        }
    }

    /**
     * Does the zone ID belong to this site's domain? Needs Zone › Zone › Read,
     * so after a successful purge a failed check is only a note.
     */
    public function checkZone(bool $purged = false): void
    {
        $response = $this->cloudflare->client()->get('zones/' . rawurlencode($this->cloudflare->zoneId()));
        $name     = $response->json['result']['name'] ?? null;
        $host     = $this->host();

        if ($name !== null && $host === null) {
            $this->step(true, 'Zone ID belongs to ' . $name);
        } elseif ($name !== null) {
            $matches = $host === $name || str_ends_with($host, '.' . $name);
            $this->step($matches, 'Zone ID belongs to ' . $name . ($matches ? '' : ', not ' . $host));
        } elseif ($purged) {
            $this->note('Zone name not checked (' . $response->error() . '), but the purge worked');
        } else {
            $this->step(false, 'Zone check failed: ' . $response->error());
        }
    }

    /** Proves the token may purge this zone, without clearing anything else */
    public function purgeProbe(): bool
    {
        if ($this->probe === null) {
            $this->note('Purge not tested: set the `probe` option to a small file Cloudflare caches');
            return false;
        }

        return $this->purge(fn () => $this->cloudflare->cache()->purgeUrls([$this->probe]), 'the probe file');
    }

    public function purgeEverything(): bool
    {
        return $this->purge(fn () => $this->cloudflare->cache()->purgeEverything(), 'the whole zone');
    }

    /**
     * Requests the probe twice, so the nearest Cloudflare edge has it cached,
     * and returns its cache status (HIT when Cloudflare caches it)
     */
    public function warmProbe(): string|null
    {
        if ($this->probe === null) {
            return null;
        }

        $this->cacheStatus();

        return $this->cacheStatus()[0];
    }

    /**
     * After a purge, the edge should have to fetch the probe from the server
     * again. Run it straight after the purge: any request (a visitor's too)
     * caches the file again, and that fresh copy is a HIT with a young age.
     */
    public function checkProbe(string|null $before): void
    {
        if ($this->probe === null) {
            return;
        }

        [$after, $age] = $this->cacheStatus();

        // a HIT older than the purge may mean the purge hasn't reached this edge yet
        if ($after === 'HIT' && $this->cachedSincePurge($age) === false) {
            sleep(2);
            [$after, $age] = $this->cacheStatus();
        }

        $seen = 'probe file went ' . ($before ?? 'no header') . ' → ' . ($after ?? 'no header');

        if ($after === null) {
            $this->note('Cache not checked: the probe file did not come back through Cloudflare');
        } elseif ($before === 'BYPASS') {
            // Cloudflare skips its cache for a URL for a short while after it was purged
            $this->note('Cache not checked: the probe file was purged moments ago and Cloudflare is still skipping it, try again in a minute');
        } elseif ($before !== 'HIT') {
            $this->note('Cache not checked: Cloudflare was not caching the probe file (' . $seen . ')');
        } elseif ($after === 'HIT' && $this->cachedSincePurge($age) === true) {
            $this->step(true, 'Cache cleared: the probe file was cached again ' . $age . 's ago, after the purge');
        } elseif (in_array($after, ['HIT', 'STALE', 'UPDATING'], true) === true) {
            // still served from the old cached copy
            $this->step(false, 'Cache not cleared yet: ' . $seen . ', try again in a minute');
        } else {
            // MISS, EXPIRED, BYPASS…: the edge went back to the server
            $this->step(true, 'Cache cleared: ' . $seen);
        }
    }

    /**
     * The full check: purge only the probe, see that it cleared, then verify
     * the token and zone. Touches nothing else in the cache.
     */
    public function check(): self
    {
        if ($this->configured()) {
            try {
                $before = $this->warmProbe();
                $purged = $this->purgeProbe();

                if ($purged) {
                    $this->checkProbe($before);
                }

                $this->verifyToken($purged);
                $this->checkZone($purged);
            } catch (Throwable $e) {
                $this->step(false, $e->getMessage());
            }
        }

        return $this;
    }

    /**
     * Flushes Kirby's pages cache, then purges the whole zone, and checks the
     * probe. Kirby first, so Cloudflare can't refetch stale cached HTML.
     */
    public function purgeAll(bool $flushKirby = true): self
    {
        if ($this->configured()) {
            if ($flushKirby === true) {
                $flushed = kirby()->cache('pages')->flush();
                $this->step($flushed, $flushed ? "Kirby's page cache flushed" : "Kirby's page cache could not be flushed");
            }

            try {
                $before = $this->warmProbe();

                if ($this->purgeEverything()) {
                    $this->checkProbe($before);
                } else {
                    // tells a wrong token or zone apart from a missing permission
                    $this->verifyToken(false);
                    $this->checkZone(false);
                }
            } catch (Throwable $e) {
                $this->step(false, $e->getMessage());
            }
        }

        return $this;
    }

    /** @return array{ok: bool, lines: string[]} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'lines' => $this->lines];
    }

    /** Cloudflare sometimes leaves out the Age header, and then a HIT can't count as fresh */
    private function cachedSincePurge(int|null $age): bool
    {
        return $age !== null && $age <= microtime(true) - $this->purgedAt;
    }

    /** Runs a purge and turns a refusal into a reason (wrong zone, missing permission…) */
    private function purge(callable $purge, string $what): bool
    {
        $this->purgedAt = microtime(true);

        try {
            $purge();
        } catch (ApiException $e) {
            return $this->step(false, 'Cloudflare refused the purge of ' . $what . ': ' . $e->getMessage());
        }

        return $this->step(true, 'Cloudflare accepted the purge of ' . $what);
    }

    /** The host the zone should serve: the probe's, else the site's */
    private function host(): string|null
    {
        $host = parse_url($this->probe ?? (string)site()->url(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * @return array{string|null, int|null} HIT, MISS, DYNAMIC… (null when the
     *         request did not pass through Cloudflare) and the copy's age in seconds
     */
    private function cacheStatus(): array
    {
        try {
            $headers = $this->cloudflare->client()->headersOf((string)$this->probe);
        } catch (Throwable) {
            return [null, null];
        }

        $status = $headers['cf-cache-status'] ?? null;
        $age    = $headers['age'] ?? null;

        return [
            $status !== null ? strtoupper(trim((string)$status)) : null,
            $age !== null ? (int)$age : null,
        ];
    }
}
