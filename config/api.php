<?php

/**
 * Panel API routes, under /api/cloudflare-tools/…
 *
 * Kirby's API already requires a logged-in Panel user and a CSRF token for
 * every write. Each route then checks the plugin permission it needs (see
 * Guard) and calls Cloudflare server-side: the token never reaches the browser.
 */

use IanHobbs\CloudflareTools\Api\Analytics;
use IanHobbs\CloudflareTools\Api\Settings;
use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Diagnostics;
use IanHobbs\CloudflareTools\Licensing\LicenseManager;
use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Panel\Guard;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;

// built per call, never memoised: a worker-mode server (FrankenPHP,
// RoadRunner) keeps this closure across requests and config changes
$cloudflare = fn (): Cloudflare => Cloudflare::fromOptions();

// runs a read that may fail on its own (a permission the token lacks)
// without failing the whole tab; the error goes next to the data instead
$attempt = function (Closure $read): array {
    try {
        return ['data' => $read(), 'error' => null];
    } catch (Throwable $e) {
        return ['data' => null, 'error' => $e->getMessage()];
    }
};

// security settings follow the security permission, the rest `settings`
$settingsPermission = fn (string $group): string => $group === 'security' ? 'security' : 'settings';

$dnsMode = fn (): string => in_array(Options::get('dns', 'read'), ['read', 'write'], true) ? Options::get('dns', 'read') : 'off';

return [
    [
        'pattern' => 'cloudflare-tools/status',
        'method'  => 'GET',
        'action'  => function () use ($cloudflare, $attempt, $dnsMode) {
            return Guard::run(null, function () use ($cloudflare, $attempt, $dnsMode) {
                $cf     = $cloudflare();
                // the once-a-day licence check happens here, in the Panel,
                // never on a visitor's request; it never throws
                $license = LicenseManager::fromOptions();

                try {
                    $license->refresh();
                } catch (Throwable) {
                    // an unwritable site/config must not take the panel down
                }

                $status = [
                    'license'     => $license->info() + ['manage' => kirby()->user()?->isAdmin() === true],
                    'configured'  => $cf->isConfigured(),
                    'missing'     => $cf->missing(),
                    'permissions' => Guard::all(),
                    'dns'         => $dnsMode(),
                    'autoPurge'   => (bool)Options::get('autoPurge', false),
                    'zone'        => null,
                    'zoneError'   => null,
                    'settings'    => null,
                ];

                if ($status['configured'] === false) {
                    return $status;
                }

                $zone                = $attempt(fn () => $cf->zone()->summary());
                $status['zone']      = $zone['data'];
                $status['zoneError'] = $zone['error'];

                // the quick switches on the overview
                $settings = $attempt(fn () => $cf->settings()->all());

                if ($settings['data'] !== null) {
                    $all = $settings['data'];

                    $status['settings'] = [
                        'developmentMode' => ($all['development_mode']['value'] ?? 'off') === 'on',
                        'developmentLeft' => $all['development_mode']['time_remaining'] ?? 0,
                        'securityLevel'   => $all['security_level']['value'] ?? null,
                        'ssl'             => $all['ssl']['value'] ?? null,
                        'alwaysUseHttps'  => ($all['always_use_https']['value'] ?? 'off') === 'on',
                    ];
                } else {
                    $status['settingsError'] = $settings['error'];
                }

                return $status;
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/analytics',
        'method'  => 'GET',
        'action'  => function () use ($cloudflare) {
            return Guard::run('analytics', function () use ($cloudflare) {
                $days    = (int)($this->requestQuery('days') ?? Options::get('defaultDays', 7));
                $traffic = $cloudflare()->analytics()->traffic($days);

                $traffic['totals']['bytesHuman']       = Analytics::humanBytes($traffic['totals']['bytes']);
                $traffic['totals']['cachedBytesHuman'] = Analytics::humanBytes($traffic['totals']['cachedBytes']);

                return $traffic;
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/purge',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare) {
            return Guard::run('purge', function () use ($cloudflare) {
                $type  = (string)$this->requestBody('type', 'everything');
                $items = $this->requestBody('items', []);

                if ($type === 'everything' && Options::get('flushKirby', true) === true) {
                    // Kirby first, so Cloudflare can't refetch stale cached HTML
                    kirby()->cache('pages')->flush();
                }

                $count = $cloudflare()->cache()->purge($type, is_array($items) ? $items : (string)$items);

                return [
                    'purged'  => $count,
                    'message' => $type === 'everything'
                        ? 'Cloudflare is clearing the whole cache'
                        : 'Cloudflare is clearing ' . $count . ' ' . ($count === 1 ? 'item' : 'items'),
                ];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/settings/(:any)',
        'method'  => 'GET',
        'action'  => function (string $group) use ($cloudflare, $attempt, $settingsPermission) {
            return Guard::run(null, function () use ($group, $cloudflare, $attempt, $settingsPermission) {
                $cf     = $cloudflare();
                $result = $cf->settings()->group($group, null, Guard::can($settingsPermission($group)));

                if ($group === 'cache') {
                    $tiered = $attempt(fn () => [
                        'tiered' => $cf->cache()->tieredCaching(),
                        'smart'  => $cf->cache()->smartTopology(),
                    ]);

                    $result['tiered']      = $tiered['data'];
                    $result['tieredError'] = $tiered['error'];
                }

                return $result;
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/settings/(:any)',
        'method'  => 'PATCH',
        'action'  => function (string $group) use ($cloudflare, $settingsPermission) {
            return Guard::run($settingsPermission($group), function () use ($group, $cloudflare) {
                $changes = (array)$this->requestBody('changes', []);

                // only settings of this group, so the permission check holds
                foreach (array_keys($changes) as $id) {
                    if ((Settings::CATALOGUE[$id]['group'] ?? null) !== $group) {
                        throw new InvalidArgumentException(message: 'The setting "' . $id . '" is not part of ' . $group);
                    }
                }

                return $cloudflare()->settings()->applyPanelChanges($changes);
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/tiered-cache',
        'method'  => 'PATCH',
        'action'  => function () use ($cloudflare) {
            return Guard::run('settings', function () use ($cloudflare) {
                $cache  = $cloudflare()->cache();
                $result = [];

                if ($this->requestBody('tiered') !== null) {
                    $result['tiered'] = $cache->setTieredCaching((bool)$this->requestBody('tiered'));
                }

                if ($this->requestBody('smart') !== null) {
                    $result['smart'] = $cache->setSmartTopology((bool)$this->requestBody('smart'));
                }

                return $result;
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/under-attack',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare) {
            return Guard::run('security', function () use ($cloudflare) {
                $on    = (bool)$this->requestBody('enabled', false);
                $level = $cloudflare()->security()->underAttack($on);

                return [
                    'securityLevel' => $level,
                    'message'       => $on ? 'I\'m Under Attack mode is on' : 'Security level back to ' . str_replace('_', ' ', $level),
                ];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/zone/pause',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare) {
            return Guard::run('settings', function () use ($cloudflare) {
                return $cloudflare()->zone()->setPaused((bool)$this->requestBody('paused', false));
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/security',
        'method'  => 'GET',
        'action'  => function () use ($cloudflare, $attempt) {
            return Guard::run(null, function () use ($cloudflare, $attempt) {
                $security = $cloudflare()->security();
                $bots     = $attempt(fn () => $security->botManagement());
                $rules    = $attempt(fn () => $security->accessRules());

                return [
                    'bots'       => $bots['data'] === null ? null : array_intersect_key($bots['data'], array_flip(['fight_mode', 'ai_bots_protection'])),
                    'botsError'  => $bots['error'],
                    'rules'      => $rules['data']['rules'] ?? [],
                    'total'      => $rules['data']['total'] ?? 0,
                    'rulesError' => $rules['error'],
                    'modes'      => IanHobbs\CloudflareTools\Api\Security::MODES,
                    'writable'   => Guard::can('security'),
                ];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/security/bots',
        'method'  => 'PATCH',
        'action'  => function () use ($cloudflare) {
            return Guard::run('security', function () use ($cloudflare) {
                $changes = [];

                if ($this->requestBody('fight_mode') !== null) {
                    $changes['fight_mode'] = (bool)$this->requestBody('fight_mode');
                }

                if ($this->requestBody('ai_bots_protection') !== null) {
                    $value = (string)$this->requestBody('ai_bots_protection');

                    if (in_array($value, ['block', 'disabled', 'only_on_ad_pages'], true) === false) {
                        throw new InvalidArgumentException(message: 'Unknown AI bot setting "' . $value . '"');
                    }

                    $changes['ai_bots_protection'] = $value;
                }

                $result = $cloudflare()->security()->updateBotManagement($changes);

                return array_intersect_key($result, array_flip(['fight_mode', 'ai_bots_protection']));
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/security/rules',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare) {
            return Guard::run('security', function () use ($cloudflare) {
                return $cloudflare()->security()->addAccessRule(
                    (string)$this->requestBody('mode', 'block'),
                    (string)$this->requestBody('value', ''),
                    null,
                    (string)$this->requestBody('notes', '')
                );
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/security/rules/(:any)',
        'method'  => 'DELETE',
        'action'  => function (string $id) use ($cloudflare) {
            return Guard::run('security', function () use ($id, $cloudflare) {
                $cloudflare()->security()->deleteAccessRule($id);

                return ['deleted' => $id];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/ssl',
        'method'  => 'GET',
        'action'  => function () use ($cloudflare, $attempt) {
            return Guard::run(null, function () use ($cloudflare, $attempt) {
                $ssl          = $cloudflare()->ssl();
                $universal    = $attempt(fn () => $ssl->universal());
                $certificates = $attempt(fn () => $ssl->certificates());

                return [
                    'universal'         => $universal['data'],
                    'certificates'      => $certificates['data'] ?? [],
                    'certificatesError' => $certificates['error'] ?? $universal['error'],
                ];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/dns',
        'method'  => 'GET',
        'action'  => function () use ($cloudflare, $dnsMode) {
            return Guard::run(null, function () use ($cloudflare, $dnsMode) {
                if ($dnsMode() === 'off') {
                    throw new PermissionException(message: 'DNS is switched off for this site (option `dns`)');
                }

                return [
                    'records'  => $cloudflare()->dns()->records(),
                    'types'    => IanHobbs\CloudflareTools\Api\Dns::TYPES,
                    'writable' => $dnsMode() === 'write' && Guard::can('dns'),
                ];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/dns',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare, $dnsMode) {
            return Guard::run('dns', function () use ($cloudflare, $dnsMode) {
                if ($dnsMode() !== 'write') {
                    throw new PermissionException(message: 'DNS is read-only for this site: set the `dns` option to "write"');
                }

                return $cloudflare()->dns()->create((array)$this->requestBody());
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/dns/(:any)',
        'method'  => 'PATCH',
        'action'  => function (string $id) use ($cloudflare, $dnsMode) {
            return Guard::run('dns', function () use ($id, $cloudflare, $dnsMode) {
                if ($dnsMode() !== 'write') {
                    throw new PermissionException(message: 'DNS is read-only for this site: set the `dns` option to "write"');
                }

                return $cloudflare()->dns()->update($id, (array)$this->requestBody());
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/dns/(:any)',
        'method'  => 'DELETE',
        'action'  => function (string $id) use ($cloudflare, $dnsMode) {
            return Guard::run('dns', function () use ($id, $cloudflare, $dnsMode) {
                if ($dnsMode() !== 'write') {
                    throw new PermissionException(message: 'DNS is read-only for this site: set the `dns` option to "write"');
                }

                $cloudflare()->dns()->delete($id);

                return ['deleted' => $id];
            });
        },
    ],
    [
        'pattern' => 'cloudflare-tools/diagnostics',
        'method'  => 'POST',
        'action'  => function () use ($cloudflare) {
            // purges the probe file only, so the purge permission covers it
            return Guard::run('purge', function () use ($cloudflare) {
                return Diagnostics::fromOptions($cloudflare())->check()->toArray();
            });
        },
    ],
];
