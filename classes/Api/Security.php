<?php

namespace IanHobbs\CloudflareTools\Api;

use InvalidArgumentException;

/**
 * Bot Fight Mode, "I'm Under Attack" and IP Access Rules — all Free plan.
 *
 * Token permissions: Zone › Bot Management › Read/Edit for bots;
 * Zone › Firewall Services › Read/Edit for IP Access Rules;
 * Zone › Zone Settings › Edit for the security level.
 */
final class Security extends Endpoint
{
    /** IP Access Rule actions; "whitelist" is Cloudflare's name for Allow */
    public const MODES = [
        'block'             => 'Block',
        'managed_challenge' => 'Managed Challenge',
        'challenge'         => 'Interactive Challenge',
        'js_challenge'      => 'JavaScript Challenge',
        'whitelist'         => 'Allow',
    ];

    // Bot Management fields a Free zone may write. The read also returns
    // computed ones (using_latest_model, stale_zone_configuration…) that a
    // PUT must not echo back.
    public const BOT_FIELDS = ['fight_mode', 'enable_js', 'ai_bots_protection', 'crawler_protection', 'is_robots_txt_managed'];

    private const PREVIOUS_LEVEL = 'security-level-before-attack';

    public function botManagement(): array
    {
        return (array)$this->client()->get($this->zonePath('bot_management'))->result();
    }

    /**
     * Changes Bot Management fields. The PUT replaces the configuration, so
     * the current writable fields are read first and sent back with the change.
     */
    public function updateBotManagement(array $changes): array
    {
        $unknown = array_diff(array_keys($changes), self::BOT_FIELDS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown bot setting: ' . implode(', ', $unknown));
        }

        $current = array_intersect_key($this->botManagement(), array_flip(self::BOT_FIELDS));

        return (array)$this->client()->put($this->zonePath('bot_management'), [...$current, ...$changes])->result();
    }

    public function setBotFightMode(bool $on): array
    {
        return $this->updateBotManagement(['fight_mode' => $on]);
    }

    /**
     * Cloudflare is moving "Block AI bots" to per-behaviour AI bot policies
     * (September 2026); this sets the legacy field while the API still takes it.
     */
    public function setAiBotsProtection(string $value): array
    {
        if (in_array($value, ['block', 'disabled', 'only_on_ad_pages'], true) === false) {
            throw new InvalidArgumentException('AI bot protection is "block", "only_on_ad_pages" or "disabled"');
        }

        return $this->updateBotManagement(['ai_bots_protection' => $value]);
    }

    public function isUnderAttack(): bool
    {
        return $this->cloudflare->settings()->value('security_level') === 'under_attack';
    }

    /**
     * "I'm Under Attack" mode: a challenge for every visitor. Remembers the
     * level it replaced, and goes back to it (or medium) when switched off.
     */
    public function underAttack(bool $on): string
    {
        $settings = $this->cloudflare->settings();

        if ($on === true) {
            $current = $settings->value('security_level');

            if ($current !== 'under_attack') {
                $this->cloudflare->store(self::PREVIOUS_LEVEL, $current, 60 * 24 * 30);
            }

            return (string)$settings->set('security_level', 'under_attack');
        }

        $previous = $this->cloudflare->stored(self::PREVIOUS_LEVEL) ?? 'medium';
        $this->cloudflare->forget(self::PREVIOUS_LEVEL);

        return (string)$settings->set('security_level', $previous === 'under_attack' ? 'medium' : $previous);
    }

    /** @return array{rules: array, total: int} */
    public function accessRules(int $page = 1, int $perPage = 50): array
    {
        $response = $this->client()->get($this->zonePath('firewall/access_rules/rules'), [
            'page'     => max(1, $page),
            'per_page' => min(1000, max(5, $perPage)),
        ]);

        return [
            'rules' => array_map(self::normaliseRule(...), (array)$response->result()),
            'total' => (int)($response->info()['total_count'] ?? 0),
        ];
    }

    /**
     * Blocks, challenges or allows an IP, range, country (two-letter code)
     * or ASN. The target is worked out from the value when not given.
     */
    public function addAccessRule(string $mode, string $value, string|null $target = null, string $notes = ''): array
    {
        if (isset(self::MODES[$mode]) === false) {
            throw new InvalidArgumentException('Unknown action "' . $mode . '", use one of: ' . implode(', ', array_keys(self::MODES)));
        }

        $value    = trim($value);
        $target ??= self::detectTarget($value);

        if ($target === 'country') {
            $value = strtoupper($value);
        }

        $rule = $this->client()->post($this->zonePath('firewall/access_rules/rules'), [
            'mode'          => $mode,
            'configuration' => ['target' => $target, 'value' => $value],
            'notes'         => trim($notes),
        ])->result();

        return self::normaliseRule((array)$rule);
    }

    public function deleteAccessRule(string $id): void
    {
        $this->client()->delete($this->zonePath('firewall/access_rules/rules/' . rawurlencode($id)))->result();
    }

    /** ip, ip6, ip_range, country or asn — whichever the value looks like */
    public static function detectTarget(string $value): string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'ip';
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'ip6';
        }

        if (preg_match('~^(.+)/(\d{1,3})$~', $value, $match) === 1 && filter_var($match[1], FILTER_VALIDATE_IP) !== false) {
            return 'ip_range';
        }

        // two-letter country codes, plus Cloudflare's T1 for Tor
        if (preg_match('/^([A-Za-z]{2}|T1)$/', $value) === 1) {
            return 'country';
        }

        if (preg_match('/^(AS)?\d{1,10}$/i', $value) === 1) {
            return 'asn';
        }

        throw new InvalidArgumentException('"' . $value . '" is not an IP address, range, two-letter country code or ASN');
    }

    public static function normaliseRule(array $rule): array
    {
        return [
            'id'      => $rule['id'] ?? null,
            'mode'    => $rule['mode'] ?? null,
            'target'  => $rule['configuration']['target'] ?? null,
            'value'   => $rule['configuration']['value'] ?? null,
            'notes'   => $rule['notes'] ?? '',
            // rules made for the whole account show up on each zone, read-only here
            'scope'   => $rule['scope']['type'] ?? 'zone',
            'created' => $rule['created_on'] ?? null,
        ];
    }
}
