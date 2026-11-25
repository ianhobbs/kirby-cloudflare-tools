<?php

namespace IanHobbs\CloudflareTools\Licensing;

use IanHobbs\CloudflareTools\ApiException;

/**
 * The seller that issued a licence key and counts its activations.
 *
 * Results are normalised so LicenseManager doesn't care who sold the key:
 *   status      'active' or 'inactive'
 *   activation  the seller's ID for this website's activation
 *   activates   false for a key without activations (no limit): it is only
 *               ever validated; null when the seller didn't say
 *   limit       how many websites the key may be activated on (null: no limit)
 *   expires     ISO 8601 date, or null
 *   display     a masked form of the key, safe to show in the Panel
 *
 * Methods throw ApiException: status 0 or 5xx when the seller can't be
 * reached (LicenseManager keeps its last answer), 4xx when it refused.
 */
interface Provider
{
    /** Whether the plugin knows which store to check keys against */
    public function isConfigured(): bool;

    /**
     * Activates the key for this website; a key without activations is
     * validated instead, and comes back with no activation ID
     *
     * @return array{status: string, activation: string|null, activates: bool|null, limit: int|null, expires: string|null, display: string|null}
     */
    public function activate(string $key, string $label): array;

    /** @return array{status: string, activation: string|null, activates: bool|null, limit: int|null, expires: string|null, display: string|null} */
    public function validate(string $key, string|null $activation): array;

    /** Frees the activation for another website */
    public function deactivate(string $key, string $activation): void;
}
