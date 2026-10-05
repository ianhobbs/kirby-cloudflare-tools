<?php

namespace IanHobbs\CloudflareTools\Api;

use InvalidArgumentException;
use Throwable;

/**
 * Zone settings: cache level, browser TTL, security level, SSL mode, HTTP/3…
 *
 * get() and set() take any setting ID Cloudflare knows. The Panel only offers
 * the ones in CATALOGUE — settings every plan can change, Free included —
 * and validates values against it before anything is sent.
 *
 * Token permissions: Zone › Zone Settings › Read, and Edit to change them.
 */
final class Settings extends Endpoint
{
    /** Panel groups, in tab order */
    public const GROUPS = [
        'cache'    => 'Caching',
        'security' => 'Security',
        'ssl'      => 'SSL/TLS',
        'speed'    => 'Speed',
        'network'  => 'Network',
    ];

    public const CATALOGUE = [
        // ── Caching ─────────────────────────────────────────────────────────
        'development_mode' => [
            'group' => 'cache',
            'type'  => 'toggle',
            'label' => 'Development Mode',
            'help'  => 'Bypasses the cache for three hours so changes show straight away, then switches itself off.',
        ],
        'cache_level' => [
            'group'   => 'cache',
            'type'    => 'select',
            'label'   => 'Caching level',
            'help'    => 'How the query string affects what Cloudflare caches.',
            'options' => [
                'aggressive' => 'Standard',
                'simplified' => 'Ignore query string',
                'basic'      => 'No query string',
            ],
        ],
        'browser_cache_ttl' => [
            'group'   => 'cache',
            'type'    => 'select',
            'int'     => true,
            'label'   => 'Browser Cache TTL',
            'help'    => 'How long browsers keep cached files when the origin says less.',
            'options' => [
                0        => 'Respect existing headers',
                1800     => '30 minutes',
                3600     => '1 hour',
                7200     => '2 hours',
                14400    => '4 hours',
                28800    => '8 hours',
                57600    => '16 hours',
                86400    => '1 day',
                172800   => '2 days',
                259200   => '3 days',
                345600   => '4 days',
                432000   => '5 days',
                691200   => '8 days',
                1382400  => '16 days',
                2073600  => '24 days',
                2678400  => '1 month',
                5356800  => '2 months',
                16070400 => '6 months',
                31536000 => '1 year',
            ],
        ],
        'always_online' => [
            'group' => 'cache',
            'type'  => 'toggle',
            'label' => 'Always Online',
            'help'  => 'Serves pages from the Internet Archive while the origin is down.',
        ],

        // ── Security ────────────────────────────────────────────────────────
        'security_level' => [
            'group'   => 'security',
            'type'    => 'select',
            'label'   => 'Security level',
            'help'    => 'Which visitors get a challenge page. "I\'m Under Attack" challenges everyone.',
            'options' => [
                'essentially_off' => 'Essentially off',
                'low'             => 'Low',
                'medium'          => 'Medium',
                'high'            => 'High',
                'under_attack'    => 'I\'m Under Attack!',
            ],
        ],
        'challenge_ttl' => [
            'group'   => 'security',
            'type'    => 'select',
            'int'     => true,
            'label'   => 'Challenge Passage',
            'help'    => 'How long a visitor who solved a challenge is left alone.',
            'options' => [
                300      => '5 minutes',
                900      => '15 minutes',
                1800     => '30 minutes',
                2700     => '45 minutes',
                3600     => '1 hour',
                7200     => '2 hours',
                10800    => '3 hours',
                14400    => '4 hours',
                28800    => '8 hours',
                57600    => '16 hours',
                86400    => '1 day',
                604800   => '1 week',
                2592000  => '1 month',
                31536000 => '1 year',
            ],
        ],
        'browser_check' => [
            'group' => 'security',
            'type'  => 'toggle',
            'label' => 'Browser Integrity Check',
            'help'  => 'Challenges requests with headers that spammers and bots commonly send.',
        ],
        'email_obfuscation' => [
            'group' => 'security',
            'type'  => 'toggle',
            'label' => 'Email Address Obfuscation',
            'help'  => 'Hides email addresses on your pages from scrapers.',
        ],
        'hotlink_protection' => [
            'group' => 'security',
            'type'  => 'toggle',
            'label' => 'Hotlink Protection',
            'help'  => 'Stops other sites embedding your images.',
        ],

        // ── SSL/TLS ─────────────────────────────────────────────────────────
        'ssl' => [
            'group'   => 'ssl',
            'type'    => 'select',
            'label'   => 'Encryption mode',
            'help'    => 'How Cloudflare connects to your server. Use Full (strict) when the server has a valid certificate.',
            'options' => [
                'off'      => 'Off (not secure)',
                'flexible' => 'Flexible',
                'full'     => 'Full',
                'strict'   => 'Full (strict)',
            ],
        ],
        'always_use_https' => [
            'group' => 'ssl',
            'type'  => 'toggle',
            'label' => 'Always Use HTTPS',
            'help'  => 'Redirects every http:// request to https://.',
        ],
        'automatic_https_rewrites' => [
            'group' => 'ssl',
            'type'  => 'toggle',
            'label' => 'Automatic HTTPS Rewrites',
            'help'  => 'Rewrites http:// links to https:// where possible, to avoid mixed content.',
        ],
        'min_tls_version' => [
            'group'   => 'ssl',
            'type'    => 'select',
            'label'   => 'Minimum TLS version',
            'options' => [
                '1.0' => 'TLS 1.0',
                '1.1' => 'TLS 1.1',
                '1.2' => 'TLS 1.2',
                '1.3' => 'TLS 1.3',
            ],
        ],
        'tls_1_3' => [
            'group' => 'ssl',
            'type'  => 'toggle',
            'label' => 'TLS 1.3',
        ],
        'opportunistic_encryption' => [
            'group' => 'ssl',
            'type'  => 'toggle',
            'label' => 'Opportunistic Encryption',
        ],

        // ── Speed ───────────────────────────────────────────────────────────
        'http3' => [
            'group' => 'speed',
            'type'  => 'toggle',
            'label' => 'HTTP/3 (QUIC)',
        ],
        '0rtt' => [
            'group' => 'speed',
            'type'  => 'toggle',
            'label' => '0-RTT Connection Resumption',
        ],
        'early_hints' => [
            'group' => 'speed',
            'type'  => 'toggle',
            'label' => 'Early Hints',
            'help'  => 'Sends 103 Early Hints so browsers start loading assets sooner.',
        ],
        'rocket_loader' => [
            'group' => 'speed',
            'type'  => 'toggle',
            'label' => 'Rocket Loader',
            'help'  => 'Defers JavaScript. Test carefully: it can break scripts that expect to run in order.',
        ],

        // ── Network ─────────────────────────────────────────────────────────
        'ipv6' => [
            'group' => 'network',
            'type'  => 'toggle',
            'label' => 'IPv6 compatibility',
        ],
        'websockets' => [
            'group' => 'network',
            'type'  => 'toggle',
            'label' => 'WebSockets',
        ],
        'ip_geolocation' => [
            'group' => 'network',
            'type'  => 'toggle',
            'label' => 'IP Geolocation',
            'help'  => 'Adds the CF-IPCountry header to requests.',
        ],
        'opportunistic_onion' => [
            'group' => 'network',
            'type'  => 'toggle',
            'label' => 'Onion Routing',
        ],
    ];

    /**
     * Every setting of the zone, keyed by ID
     *
     * @return array<string, array{value: mixed, editable: bool, modified_on: string|null, time_remaining?: int}>
     */
    public function all(): array
    {
        $settings = [];

        foreach ((array)$this->client()->get($this->zonePath('settings'))->result() as $setting) {
            $settings[$setting['id']] = [
                'value'       => $setting['value'] ?? null,
                'editable'    => (bool)($setting['editable'] ?? false),
                'modified_on' => $setting['modified_on'] ?? null,
            ] + (isset($setting['time_remaining']) ? ['time_remaining' => (int)$setting['time_remaining']] : []);
        }

        return $settings;
    }

    /** One setting's full record (value, editable, modified_on…) */
    public function get(string $id): array
    {
        return (array)$this->client()->get($this->zonePath('settings/' . rawurlencode($id)))->result();
    }

    public function value(string $id): mixed
    {
        return $this->get($id)['value'] ?? null;
    }

    /** Changes one setting; returns the value Cloudflare now reports */
    public function set(string $id, mixed $value): mixed
    {
        $result = $this->client()->patch($this->zonePath('settings/' . rawurlencode($id)), ['value' => $value])->result();

        return $result['value'] ?? null;
    }

    /**
     * Applies Panel form changes one by one, so one refusal (a setting the
     * plan or token can't change) doesn't stop the rest
     *
     * @param  array<string, mixed> $changes setting ID => Panel value
     * @return array{saved: array<string, mixed>, failed: array<string, string>}
     */
    public function applyPanelChanges(array $changes): array
    {
        $saved  = [];
        $failed = [];

        foreach ($changes as $id => $value) {
            try {
                $saved[$id] = self::toPanel($id, $this->set($id, self::fromPanel($id, $value)));
            } catch (Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * One group as Kirby form fields plus their current values. Settings the
     * zone doesn't report are left out; ones it won't let change are disabled.
     */
    public function group(string $group, array|null $all = null, bool $writable = true): array
    {
        if (isset(self::GROUPS[$group]) === false) {
            throw new InvalidArgumentException('Unknown settings group "' . $group . '"');
        }

        $all  ??= $this->all();
        $fields = [];
        $value  = [];
        $meta   = [];

        foreach (self::CATALOGUE as $id => $setting) {
            if ($setting['group'] !== $group || isset($all[$id]) === false) {
                continue;
            }

            $fields[$id] = self::field($id, $setting, $writable && $all[$id]['editable']);
            $value[$id]  = self::toPanel($id, $all[$id]['value']);

            if (isset($all[$id]['time_remaining'])) {
                $meta[$id] = ['time_remaining' => $all[$id]['time_remaining']];
            }
        }

        return ['fields' => $fields, 'value' => $value, 'meta' => $meta];
    }

    /** A Cloudflare value as the Panel field shows it */
    public static function toPanel(string $id, mixed $value): mixed
    {
        $setting = self::CATALOGUE[$id] ?? null;

        return match ($setting['type'] ?? null) {
            // tls_1_3 also knows "zrt"; anything but "off" counts as on
            'toggle' => $value !== 'off' && $value !== false && $value !== null,
            'select' => (string)$value,
            default  => $value,
        };
    }

    /** A Panel value as Cloudflare expects it, validated against the catalogue */
    public static function fromPanel(string $id, mixed $value): mixed
    {
        $setting = self::CATALOGUE[$id] ?? throw new InvalidArgumentException('The setting "' . $id . '" cannot be changed from the Panel');

        if ($setting['type'] === 'toggle') {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? 'on' : 'off';
        }

        $allowed = array_map('strval', array_keys($setting['options']));

        if (in_array((string)$value, $allowed, true) === false) {
            throw new InvalidArgumentException('"' . $value . '" is not a valid value for ' . $setting['label']);
        }

        return ($setting['int'] ?? false) ? (int)$value : (string)$value;
    }

    private static function field(string $id, array $setting, bool $editable): array
    {
        $field = [
            'name'     => $id,
            'label'    => $setting['label'],
            'type'     => $setting['type'],
            'help'     => $setting['help'] ?? null,
            'disabled' => $editable === false,
            'width'    => '1/2',
        ];

        if ($setting['type'] === 'toggle') {
            $field['text'] = ['Off', 'On'];
        } else {
            $field['empty']   = false;
            $field['options'] = [];

            foreach ($setting['options'] as $value => $text) {
                $field['options'][] = ['value' => (string)$value, 'text' => $text];
            }
        }

        return $field;
    }
}
