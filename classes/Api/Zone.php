<?php

namespace IanHobbs\CloudflareTools\Api;

use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Client;
use InvalidArgumentException;

/**
 * The zone itself: name, status, plan, name servers; pausing Cloudflare.
 *
 * Token permission: Zone › Zone › Read (Zone › Zone › Edit to pause).
 */
final class Zone extends Endpoint
{
    public function details(): array
    {
        return (array)$this->client()->get($this->zonePath())->result();
    }

    /** The zone's details, flattened for the Panel */
    public function summary(): array
    {
        return self::summarise($this->details());
    }

    public static function summarise(array $zone): array
    {
        return [
            'id'                  => $zone['id'] ?? null,
            'name'                => $zone['name'] ?? null,
            'status'              => $zone['status'] ?? null,
            'paused'              => (bool)($zone['paused'] ?? false),
            'type'                => $zone['type'] ?? null,
            'plan'                => $zone['plan']['name'] ?? null,
            'nameServers'         => array_values($zone['name_servers'] ?? []),
            'originalNameServers' => array_values($zone['original_name_servers'] ?? []),
            'activatedOn'         => $zone['activated_on'] ?? null,
            'account'             => $zone['account']['name'] ?? null,
            'accountId'           => $zone['account']['id'] ?? null,
        ];
    }

    /**
     * Pausing sends traffic straight to the origin: no cache, no WAF, no
     * proxy. DNS keeps resolving. Needs Zone › Zone › Edit.
     */
    public function setPaused(bool $paused): array
    {
        return self::summarise((array)$this->client()->patch($this->zonePath(), ['paused' => $paused])->result());
    }

    /**
     * Finds the zone that serves a domain. Tries the host itself, then each
     * parent (www.shop.example.com → shop.example.com → example.com), so a
     * site on a subdomain finds its zone too.
     */
    public static function resolve(Client $client, string $domain): string
    {
        $labels = explode('.', self::normaliseDomain($domain));

        while (count($labels) >= 2) {
            $name = implode('.', $labels);
            $zone = $client->get('zones', ['name' => $name, 'status' => 'active'])->result()[0]['id'] ?? null;

            if ($zone !== null) {
                if (preg_match('/^[a-f0-9]{32}$/', (string)$zone) !== 1) {
                    throw new ApiException('Unexpected Cloudflare zone ID format');
                }

                return $zone;
            }

            array_shift($labels);
        }

        throw new ApiException('No active Cloudflare zone found for "' . $domain . '". Check the domain, and that the token may read that zone.');
    }

    /** Reduces any URL or host input to a bare, validated hostname */
    public static function normaliseDomain(string $domain): string
    {
        $host = strtolower(trim($domain));
        $host = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $host); // scheme
        $host = preg_replace('~[/?#].*$~', '', (string)$host);       // path, query, fragment
        $host = preg_replace('~:\d+$~', '', (string)$host);          // port
        $host = trim((string)$host, '.');

        if ($host === '' || str_contains($host, '.') === false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Invalid domain name: "' . $domain . '"');
        }

        return $host;
    }
}
