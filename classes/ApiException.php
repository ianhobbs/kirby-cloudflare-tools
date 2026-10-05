<?php

namespace IanHobbs\CloudflareTools;

use RuntimeException;
use Throwable;

/**
 * A request Cloudflare refused, or one that never reached it.
 *
 * The message is Cloudflare's own, plus a plain-English hint for the errors a
 * wrong token or zone ID produces, so it can go straight into the Panel.
 */
final class ApiException extends RuntimeException
{
    // plain-English hints for the errors a wrong token or zone produces;
    // 9109 has none, Cloudflare's own message says which of the two it is
    private const HINTS = [
        1000  => 'token not recognised, check CLOUDFLARE_API_TOKEN',
        6003  => 'token looks malformed, check for a stray space, quote or line break',
        6111  => 'token looks malformed, check for a stray space, quote or line break',
        7000  => 'check CLOUDFLARE_ZONE_ID',
        7003  => 'check CLOUDFLARE_ZONE_ID',
        10000 => 'token is wrong, lacks the permission this action needs, or is not allowed on this zone',
    ];

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly int|null $cloudflareCode = null,
        Throwable|null $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Builds the exception for a failed response */
    public static function fromResponse(Response $response): self
    {
        $error = $response->errors()[0] ?? null;

        return new self(
            self::describe($response->status, $response->json),
            $response->status,
            isset($error['code']) ? (int)$error['code'] : null
        );
    }

    /** Cloudflare's own error message plus a hint, or just the HTTP code */
    public static function describe(int $status, array $json): string
    {
        $error   = $json['errors'][0] ?? null;
        $message = is_array($error) ? (string)($error['message'] ?? '') : '';

        if ($message === '') {
            $hint = self::statusHint($status);

            return 'HTTP ' . $status . ($hint ? ', ' . $hint : '');
        }

        $code = isset($error['code']) ? (int)$error['code'] : null;
        $hint = self::HINTS[$code] ?? self::messageHint($message) ?? self::statusHint($status);

        return $message . ($code !== null ? ' (' . $code . ')' : '') . ($hint ? ', ' . $hint : '');
    }

    private static function messageHint(string $message): string|null
    {
        // a token with Client IP Address Filtering, called from an IP it doesn't list
        if (str_contains($message, 'from location')) {
            return "the token is restricted to certain IP addresses, allow this server's IP or remove the filter";
        }

        return null;
    }

    private static function statusHint(int $status): string|null
    {
        return match ($status) {
            401, 403 => 'check the token and its permissions',
            429      => 'Cloudflare is rate limiting this account, wait a minute and try again',
            default  => null,
        };
    }
}
