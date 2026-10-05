<?php

namespace IanHobbs\CloudflareTools\Api;

use IanHobbs\CloudflareTools\Response;
use Throwable;

/**
 * Is the token valid, enabled and unexpired? Says nothing about its
 * permissions — only a call that needs them can tell. Any token may verify
 * itself; no permission is needed.
 */
final class Token extends Endpoint
{
    /**
     * @return array{status: string|null, expires: string|null, response: Response}
     *         status is active, disabled or expired, or null when the check failed
     */
    public function verify(): array
    {
        $response = $this->client()->get('user/tokens/verify');

        // account-owned tokens are only known to their account's verify endpoint
        if (isset($response->json['result']['status']) === false) {
            $account = $this->account();

            if ($account !== null) {
                $response = $this->client()->get('accounts/' . rawurlencode($account) . '/tokens/verify');
            }
        }

        return [
            'status'   => $response->json['result']['status'] ?? null,
            'expires'  => $response->json['result']['expires_on'] ?? null,
            'response' => $response,
        ];
    }

    /** The configured account, else the zone's, else the first one the token can see */
    private function account(): string|null
    {
        try {
            $account = $this->cloudflare->accountId();
        } catch (Throwable) {
            $account = null;
        }

        return $account ?? $this->client()->get('accounts')->json['result'][0]['id'] ?? null;
    }
}
