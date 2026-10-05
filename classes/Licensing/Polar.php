<?php

namespace IanHobbs\CloudflareTools\Licensing;

use Closure;
use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Client;
use Throwable;

/**
 * Licence keys sold through Polar.sh.
 *
 * Uses Polar's customer-portal licence endpoints, which need no API token:
 * nothing secret ships with the plugin. The organisation ID limits checks to
 * keys from this store, so a key from another Polar store can't validate.
 *
 * https://polar.sh/docs/features/benefits/license-keys
 */
final class Polar implements Provider
{
    // Polar › Settings › General › Organization ID, once the product exists.
    // Until then every key is saved but reported as not verified.
    public const ORGANIZATION = null;

    public const ENDPOINT = 'https://api.polar.sh';
    public const SANDBOX  = 'https://sandbox-api.polar.sh';

    private Closure $transport;

    public function __construct(
        private string|null $organization = self::ORGANIZATION,
        private string $endpoint = self::ENDPOINT,
        callable|null $transport = null,
        private int $timeout = 8
    ) {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : Client::remote(...);
    }

    public function isConfigured(): bool
    {
        return $this->organization !== null && $this->organization !== '';
    }

    public function activate(string $key, string $label): array
    {
        $json = $this->send('activate', [
            'key'             => $key,
            'organization_id' => $this->organization,
            'label'           => $label,
        ]);

        return self::normalise($json['license_key'] ?? [], $json['id'] ?? null);
    }

    public function validate(string $key, string|null $activation): array
    {
        $body = ['key' => $key, 'organization_id' => $this->organization];

        if ($activation !== null) {
            $body['activation_id'] = $activation;
        }

        try {
            $json = $this->send('validate', $body);
        } catch (ApiException $e) {
            // Polar answers 404 for revoked, disabled and expired keys too
            if ($e->status === 404) {
                return ['status' => 'inactive', 'activation' => $activation, 'limit' => null, 'expires' => null, 'display' => null];
            }

            throw $e;
        }

        return self::normalise($json, $json['activation']['id'] ?? $activation);
    }

    public function deactivate(string $key, string $activation): void
    {
        try {
            $this->send('deactivate', [
                'key'             => $key,
                'organization_id' => $this->organization,
                'activation_id'   => $activation,
            ]);
        } catch (ApiException $e) {
            // already gone is as good as removed
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    /** A Polar licence key object as a Provider result */
    public static function normalise(array $key, string|null $activation): array
    {
        $expires = $key['expires_at'] ?? null;
        $expired = $expires !== null && strtotime((string)$expires) < time();

        return [
            'status'     => ($key['status'] ?? null) === 'granted' && $expired === false ? 'active' : 'inactive',
            'activation' => $activation,
            'limit'      => isset($key['limit_activations']) ? (int)$key['limit_activations'] : null,
            'expires'    => $expires,
            'display'    => $key['display_key'] ?? null,
        ];
    }

    private function send(string $action, array $body): array
    {
        if ($this->isConfigured() === false) {
            throw new ApiException('Licence checks are not switched on yet');
        }

        try {
            [$status, $raw] = ($this->transport)(
                'POST',
                $this->endpoint . '/v1/customer-portal/license-keys/' . $action,
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                json_encode($body, JSON_UNESCAPED_SLASHES),
                $this->timeout
            );
        } catch (Throwable $e) {
            throw new ApiException('The licence server could not be reached: ' . $e->getMessage(), 0, null, $e);
        }

        $json = json_decode((string)$raw, true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        throw new ApiException(self::message((int)$status, $json), (int)$status);
    }

    private static function message(int $status, array $json): string
    {
        $detail = $json['detail'] ?? null;

        // validation errors come as a list
        if (is_array($detail)) {
            $detail = $detail[0]['msg'] ?? null;
        }

        return match (true) {
            $status === 404 => 'Licence key not found, or no longer valid',
            $status === 403 => 'This licence key can\'t be activated here: it may be revoked or expired, or already used on as many websites as it allows' . ($detail ? ' (' . $detail . ')' : ''),
            $status === 429 => 'The licence server is busy, try again in a minute',
            $status >= 500  => 'The licence server had a problem (HTTP ' . $status . ')',
            default         => 'The licence server refused the request' . ($detail ? ': ' . $detail : ' (HTTP ' . $status . ')'),
        };
    }
}
