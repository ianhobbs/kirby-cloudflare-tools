<?php

namespace IanHobbs\CloudflareTools\Api;

/**
 * Edge certificates: the free Universal SSL certificate and its status.
 * The SSL/TLS settings themselves (mode, minimum TLS…) are in Settings.
 *
 * Token permission: Zone › SSL and Certificates › Read.
 */
final class Ssl extends Endpoint
{
    /** Whether Universal SSL is on for the zone */
    public function universal(): bool
    {
        return (bool)($this->client()->get($this->zonePath('ssl/universal/settings'))->result()['enabled'] ?? false);
    }

    /** Certificate packs with their hosts, status and expiry */
    public function certificates(): array
    {
        $packs = (array)$this->client()->get($this->zonePath('ssl/certificate_packs'), ['status' => 'all'])->result();

        return array_map(self::normalisePack(...), $packs);
    }

    /** Domain-control validation status per certificate (pending ones show what's missing) */
    public function verification(): array
    {
        return (array)$this->client()->get($this->zonePath('ssl/verification'))->result();
    }

    public static function normalisePack(array $pack): array
    {
        $expires = array_filter(array_column($pack['certificates'] ?? [], 'expires_on'));
        sort($expires);

        return [
            'id'        => $pack['id'] ?? null,
            'type'      => $pack['type'] ?? null,
            'status'    => $pack['status'] ?? null,
            'hosts'     => array_values($pack['hosts'] ?? []),
            'authority' => $pack['certificate_authority'] ?? null,
            'validity'  => $pack['validity_days'] ?? null,
            // the soonest expiry among the pack's certificates
            'expires'   => $expires[0] ?? null,
        ];
    }
}
