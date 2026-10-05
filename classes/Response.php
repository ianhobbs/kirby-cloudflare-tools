<?php

namespace IanHobbs\CloudflareTools;

/**
 * One Cloudflare API response: HTTP status, decoded JSON and headers.
 *
 * Never throws on its own. Callers that only want the data use result(),
 * which throws an ApiException for a refusal; callers that report on each
 * step (Diagnostics) check ok() and read error() instead.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $json,
        public readonly array $headers = []
    ) {
    }

    public static function from(int $status, string $body, array $headers = []): self
    {
        $json = json_decode($body, true);

        return new self($status, is_array($json) ? $json : [], $headers);
    }

    /** A 2xx, and not `success: false` (REST answers carry it; GraphQL ones don't) */
    public function ok(): bool
    {
        return $this->status >= 200
            && $this->status < 300
            && ($this->json['success'] ?? true) !== false;
    }

    /** The `result` payload, or an ApiException when Cloudflare refused */
    public function result(): mixed
    {
        if ($this->ok() === false) {
            throw ApiException::fromResponse($this);
        }

        return $this->json['result'] ?? null;
    }

    /** Pagination details of a list response (page, per_page, count, total_count, total_pages) */
    public function info(): array
    {
        return $this->json['result_info'] ?? [];
    }

    /** @return array<int, array{code?: int, message?: string}> */
    public function errors(): array
    {
        return $this->json['errors'] ?? [];
    }

    /** Cloudflare's error message plus a hint, for reports */
    public function error(): string
    {
        return ApiException::describe($this->status, $this->json);
    }
}
