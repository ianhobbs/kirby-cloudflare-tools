<?php

namespace IanHobbs\CloudflareTools\Api;

use IanHobbs\CloudflareTools\Client;
use IanHobbs\CloudflareTools\Cloudflare;

/**
 * Base class for one API gate (cache, settings, DNS…).
 *
 * Subclasses get the zone they act on and the client to reach it. To add a
 * gate, extend this and register it with Cloudflare::extend() or the
 * `endpoints` option — nothing else in the plugin needs to change.
 */
abstract class Endpoint
{
    public function __construct(protected Cloudflare $cloudflare)
    {
    }

    protected function client(): Client
    {
        return $this->cloudflare->client();
    }

    /** `zones/{zone id}` plus an optional sub-path */
    protected function zonePath(string $path = ''): string
    {
        $base = 'zones/' . rawurlencode($this->cloudflare->zoneId());

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}
