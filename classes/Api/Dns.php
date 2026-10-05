<?php

namespace IanHobbs\CloudflareTools\Api;

use InvalidArgumentException;

/**
 * DNS records: list, create, change, delete; the orange-cloud proxy switch.
 *
 * Token permissions: Zone › DNS › Read, and Edit to change records.
 */
final class Dns extends Endpoint
{
    public const TYPES = ['A', 'AAAA', 'CAA', 'CNAME', 'HTTPS', 'MX', 'NS', 'PTR', 'SRV', 'SVCB', 'TXT'];

    /** Types the proxy can sit in front of */
    public const PROXIABLE = ['A', 'AAAA', 'CNAME'];

    private const PER_PAGE  = 100;
    private const MAX_PAGES = 50;

    /**
     * All records, every page fetched, sorted by type then name
     *
     * @param array $filter Cloudflare's list filters, e.g. ['type' => 'A', 'name' => 'www.example.com']
     */
    public function records(array $filter = []): array
    {
        $records = [];
        $page    = 1;

        do {
            $response = $this->client()->get($this->zonePath('dns_records'), [
                ...$filter,
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]);

            foreach ((array)$response->result() as $record) {
                $records[] = self::normalise($record);
            }

            $pages = (int)($response->info()['total_pages'] ?? 1);
        } while ($page++ < min($pages, self::MAX_PAGES));

        usort($records, fn ($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return $records;
    }

    public function create(array $record): array
    {
        $record = self::clean($record, true);

        return self::normalise((array)$this->client()->post($this->zonePath('dns_records'), $record)->result());
    }

    /** Changes only the fields given (PATCH) */
    public function update(string $id, array $changes): array
    {
        $changes = self::clean($changes, false);

        return self::normalise((array)$this->client()->patch($this->zonePath('dns_records/' . rawurlencode($id)), $changes)->result());
    }

    public function setProxied(string $id, bool $proxied): array
    {
        return $this->update($id, ['proxied' => $proxied]);
    }

    public function delete(string $id): void
    {
        $this->client()->delete($this->zonePath('dns_records/' . rawurlencode($id)))->result();
    }

    public static function normalise(array $record): array
    {
        return [
            'id'        => $record['id'] ?? null,
            'type'      => $record['type'] ?? null,
            'name'      => $record['name'] ?? null,
            'content'   => $record['content'] ?? null,
            'proxied'   => (bool)($record['proxied'] ?? false),
            'proxiable' => (bool)($record['proxiable'] ?? false),
            // 1 means "automatic"
            'ttl'       => (int)($record['ttl'] ?? 1),
            'priority'  => $record['priority'] ?? null,
            'comment'   => $record['comment'] ?? null,
            'modified'  => $record['modified_on'] ?? null,
        ];
    }

    /**
     * Keeps the fields this plugin lets you set, with their types fixed.
     * A new record needs type, name and content.
     */
    public static function clean(array $input, bool $creating): array
    {
        $record = [];

        if (isset($input['type'])) {
            $record['type'] = strtoupper(trim((string)$input['type']));

            if (in_array($record['type'], self::TYPES, true) === false) {
                throw new InvalidArgumentException('Unsupported DNS record type "' . $input['type'] . '"');
            }
        }

        foreach (['name', 'content', 'comment'] as $key) {
            if (array_key_exists($key, $input)) {
                $record[$key] = trim((string)$input[$key]);
            }
        }

        if (array_key_exists('ttl', $input)) {
            $ttl = (int)$input['ttl'];
            // 1 = automatic; otherwise Cloudflare accepts 60 to 86400 seconds
            $record['ttl'] = $ttl === 1 ? 1 : max(60, min(86400, $ttl));
        }

        if (array_key_exists('proxied', $input)) {
            $record['proxied'] = filter_var($input['proxied'], FILTER_VALIDATE_BOOL);
        }

        if (isset($input['priority']) && $input['priority'] !== '') {
            $record['priority'] = max(0, min(65535, (int)$input['priority']));
        }

        if ($creating === true) {
            foreach (['type', 'name', 'content'] as $key) {
                if (($record[$key] ?? '') === '') {
                    throw new InvalidArgumentException('A DNS record needs a ' . $key);
                }
            }

            // only A, AAAA and CNAME records can be proxied
            if (in_array($record['type'], self::PROXIABLE, true) === false) {
                unset($record['proxied']);
            }

            if ($record['type'] === 'MX') {
                $record['priority'] ??= 10;
            }
        }

        return $record;
    }
}
