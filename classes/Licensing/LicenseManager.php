<?php

namespace IanHobbs\CloudflareTools\Licensing;

use Closure;
use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Panel\Guard;
use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Kirby\Plugin\License;
use Kirby\Plugin\LicenseStatus;
use Kirby\Plugin\Plugin;

/**
 * This site's licence: which key, which website, and what the seller last
 * said about it. See LICENSE for the terms this reports on.
 *
 * Statuses, as Kirby's System view shows them:
 *   demo      a development site (localhost, *.test…, `licenseIgnore`): free
 *   missing   a public website without a key
 *   active    the seller confirmed the key for this website
 *   inactive  the seller revoked or refused it
 *   unknown   a key is saved but not confirmed (not checked yet, checks not
 *             switched on, no site URL, or the seller unreachable for 14 days)
 *
 * The key comes from the `license` option (CLOUDFLARE_TOOLS_LICENSE by
 * default) or from the Panel dialog. What the seller said is kept in
 * site/config/.cloudflare-tools-license, next to Kirby's own .license file;
 * a key from the environment is never written there.
 *
 * Nothing here ever blocks the plugin. Reading the status makes no network
 * call; only activate(), deactivate() and the daily refresh() do.
 */
final class LicenseManager
{
    public const NAME = 'Cloudflare Tools Licence';
    public const FILE = '.cloudflare-tools-license';

    // ask the seller at most once a day; trust its last "active" for 14 days
    public const CHECK_EVERY = 86400;
    public const GRACE       = 14 * 86400;

    // hosts that are always development sites (LICENSE, "Development Installation")
    private const LOCAL = ['localhost', '*.localhost', '*.local', '*.test', '*.ddev.site', '[::1]'];

    private array|null $state = null;
    private Closure $now;

    public function __construct(
        private Provider $provider,
        private string $file,
        private string|null $configuredKey = null,
        private string|null $url = null,
        private array $ignore = [],
        private string|null $shop = null,
        callable|null $now = null
    ) {
        $this->configuredKey = Options::filled($configuredKey);
        $this->shop          = Options::filled($shop);
        $this->now           = $now !== null ? Closure::fromCallable($now) : fn () => time();
    }

    public static function fromOptions(): self
    {
        $kirby  = App::instance();
        $server = (array)Options::get('licenseServer', []);

        return new self(
            provider: new Polar(
                organization: Options::filled($server['organization'] ?? null) ?? Polar::ORGANIZATION,
                endpoint: Options::filled($server['endpoint'] ?? null) ?? Polar::ENDPOINT,
                // tests answer for the seller the same way they do for Cloudflare
                transport: option(Options::PREFIX . '.transport')
            ),
            file: dirname($kirby->root('license')) . '/' . self::FILE,
            configuredKey: Options::get('license'),
            url: (string)$kirby->site()->url(),
            ignore: (array)Options::get('licenseIgnore', []),
            shop: Options::get('licenseShop')
        );
    }

    /**
     * The website as the licence counts it: host plus root directory
     * (example.com, example.com/shop), or null when Kirby has no full URL
     */
    public function website(): string|null
    {
        $parts = parse_url((string)$this->url);

        if (empty($parts['host']) === true) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['host']) . $port . rtrim($parts['path'] ?? '', '/');
    }

    /** Local, private-network or listed staging hosts need no licence */
    public function isDevelopment(): bool
    {
        $host = parse_url((string)$this->url, PHP_URL_HOST);

        if (is_string($host) === false || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach ([...self::LOCAL, ...$this->ignore] as $pattern) {
            if (fnmatch(strtolower((string)$pattern), $host) === true) {
                return true;
            }
        }

        // 127.0.0.1, 10.x, 192.168.x and the like
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function key(): string|null
    {
        return $this->configuredKey ?? Options::filled($this->state()['key'] ?? null);
    }

    /** 'config' (option or environment), 'panel' (entered in the dialog) or null */
    public function keySource(): string|null
    {
        if ($this->configuredKey !== null) {
            return 'config';
        }

        return $this->key() !== null ? 'panel' : null;
    }

    public function status(): string
    {
        if ($this->isDevelopment() === true) {
            return 'demo';
        }

        // the command line without Kirby's `url` option can't tell which site this is
        if ($this->website() === null) {
            return 'unknown';
        }

        if ($this->key() === null) {
            return 'missing';
        }

        $record = $this->record();
        $status = $record['status'] ?? null;

        if ($status === null) {
            return 'unknown';
        }

        if ($status === 'active' && $this->now() - (int)($record['verified'] ?? 0) > self::GRACE) {
            return 'unknown';
        }

        return $status;
    }

    /** Everything the Panel and CLI show; never the key itself */
    public function info(): array
    {
        $record = $this->record() ?? [];
        $status = $this->status();

        return [
            'status'     => $status,
            'label'      => self::label($status),
            'message'    => $this->message($status, $record),
            'website'    => $this->website(),
            'source'     => $this->keySource(),
            'display'    => $record['display'] ?? self::mask($this->key()),
            'limit'      => $record['limit'] ?? null,
            'expires'    => $record['expires'] ?? null,
            'checked'    => $record['checked'] ?? null,
            'verified'   => $record['verified'] ?? null,
            'verifiable' => $this->provider->isConfigured(),
            'shop'       => $this->shop,
        ];
    }

    /**
     * Activates a key for this website. With no key given, uses the one
     * already set; with a key already activated here, re-checks it instead
     * of using up a second activation.
     */
    public function activate(string|null $key = null): array
    {
        $key = Options::filled($key);

        if ($key !== null && $this->configuredKey !== null && $key !== $this->configuredKey) {
            throw new InvalidArgumentException('This site\'s licence key is set in its configuration (CLOUDFLARE_TOOLS_LICENSE): change it there');
        }

        $key ??= $this->key() ?? throw new InvalidArgumentException('Enter a licence key');

        $website = $this->website() ?? throw new InvalidArgumentException('Set Kirby\'s `url` option to this site\'s address, so the licence can be activated for it');

        if ($this->isDevelopment() === true) {
            throw new InvalidArgumentException('Development sites need no licence: activate it on the public website');
        }

        $base     = $this->base($key, $website);
        $previous = $this->record($key);

        if ($this->provider->isConfigured() === false) {
            // nothing to check against yet: keep the key, report it as not verified
            $this->save($base + ['display' => self::mask($key)]);

            return $this->info();
        }

        $result = empty($previous['activation']) === false
            ? $this->provider->validate($key, $previous['activation'])
            : $this->provider->activate($key, $website);

        $this->save($base + $result + [
            'display'  => $result['display'] ?? self::mask($key),
            'checked'  => $this->now(),
            'verified' => $this->now(),
        ]);

        return $this->info();
    }

    /**
     * Frees this website's activation, and forgets the key entered in the
     * Panel. A key in the environment stays set: remove it there too, or
     * the next check activates it again.
     */
    public function deactivate(): void
    {
        $state = $this->state();
        $key   = $this->key();

        if (
            $key !== null &&
            empty($state['activation']) === false &&
            ($state['keyHash'] ?? null) === hash('sha256', $key) &&
            $this->provider->isConfigured() === true
        ) {
            // throws when the seller can't be reached: keep the record to retry
            $this->provider->deactivate($key, $state['activation']);
        }

        $this->state = [];
        F::remove($this->file);
    }

    /**
     * Asks the seller again when the last answer is a day old (or $force).
     * Activates a key that isn't activated for this website yet — a key set
     * in the environment needs no dialog. Never throws.
     */
    public function refresh(bool $force = false): string
    {
        $key     = $this->key();
        $website = $this->website();

        if ($key === null || $website === null || $this->isDevelopment() === true || $this->provider->isConfigured() === false) {
            return $this->status();
        }

        $record = $this->record();

        if ($force === false && $record !== null && $this->now() - (int)($record['checked'] ?? 0) < self::CHECK_EVERY) {
            return $this->status();
        }

        try {
            $this->activate();
        } catch (ApiException $e) {
            $base = ($record ?? $this->base($key, $website)) + ['display' => self::mask($key)];

            if (self::unreachable($e) === true) {
                // keep the last answer, and try again tomorrow
                $this->save([...$base, 'checked' => $this->now(), 'message' => $e->getMessage()]);
            } else {
                $this->save([...$base, 'status' => 'inactive', 'checked' => $this->now(), 'verified' => $this->now(), 'message' => $e->getMessage()]);
            }
        }

        return $this->status();
    }

    /** The licence as Kirby's System view shows it */
    public function toPanel(Plugin $plugin): License
    {
        $status = $this->status();

        [$icon, $theme] = match ($status) {
            'active'   => ['check', 'positive'],
            'inactive' => ['alert', 'negative'],
            'demo'     => ['preview', 'info'],
            'missing'  => ['key', 'love'],
            default    => ['question', 'notice'],
        };

        return new License(
            plugin: $plugin,
            name: self::NAME,
            link: $this->shop,
            status: new LicenseStatus(
                value: $status,
                icon: $icon,
                label: self::label($status),
                dialog: Guard::AREA . '/license',
                theme: $theme
            )
        );
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'active'   => 'Active',
            'inactive' => 'Inactive',
            'demo'     => 'Development site',
            'missing'  => 'Activate',
            default    => 'Not verified',
        };
    }

    /** The last 4 characters, for showing which key is in use */
    public static function mask(string|null $key): string|null
    {
        return $key === null ? null : '••••' . substr($key, -4);
    }

    private function message(string $status, array $record): string
    {
        return match ($status) {
            'demo'     => 'This is a development site, so it needs no licence. Each public website needs one activation.',
            'missing'  => 'This public website has no licence key. Each public website needs one activation.',
            'active'   => 'Licensed for ' . $this->website() . '.' . (isset($record['limit']) ? ' The key covers ' . $record['limit'] . ' ' . ($record['limit'] === 1 ? 'website' : 'websites') . '.' : ''),
            'inactive' => $record['message'] ?? 'The licence key was revoked or has expired.',
            default    => match (true) {
                $this->website() === null                => 'Set Kirby\'s `url` option to this site\'s address, so the licence can be checked.',
                $this->provider->isConfigured() === false => 'The key is saved. It will be checked once licence checks are switched on.',
                isset($record['message'])                 => 'Not confirmed: ' . $record['message'],
                isset($record['verified'])                => 'The licence server hasn\'t been reachable since ' . date('j M Y', (int)$record['verified']) . '.',
                default                                   => 'The key is saved but not checked yet.',
            },
        };
    }

    /** What every record says about itself; the key only when it came from the Panel */
    private function base(string $key, string $website): array
    {
        return [
            'keyHash' => hash('sha256', $key),
            'website' => $website,
        ] + ($this->configuredKey === null ? ['key' => $key] : []);
    }

    /** The stored record, if it belongs to this key and this website */
    private function record(string|null $key = null): array|null
    {
        $state = $this->state();
        $key ??= $this->key();

        if (
            $key === null ||
            ($state['keyHash'] ?? null) !== hash('sha256', $key) ||
            ($state['website'] ?? null) !== $this->website()
        ) {
            return null;
        }

        return $state;
    }

    private function state(): array
    {
        if ($this->state === null) {
            $data        = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
            $this->state = is_array($data) ? $data : [];
        }

        return $this->state;
    }

    private function save(array $state): void
    {
        $this->state = $state;

        F::write($this->file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($this->file, 0600);
    }

    private function now(): int
    {
        return ($this->now)();
    }

    private static function unreachable(ApiException $e): bool
    {
        return $e->status === 0 || $e->status === 429 || $e->status >= 500;
    }
}
