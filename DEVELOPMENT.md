# Development Guide — cloudflare-tools

## How this repo is laid out

The repo root is **only the plugin**: the files Kirby loads and the files a
consumer downloads. Everything needed to develop it lives in `dev/`: its own
Composer project, its own Kirby install, the Pest suite and a visual dev site.

```text
kirby-cloudflare-tools/
├── index.php                 ← plugin registration and option defaults
├── index.js  index.css       ← built Panel bundle (committed)
├── classes/
│   ├── Cloudflare.php        ← the connector: zone, gates, raw request(), cache
│   ├── Client.php            ← HTTP + auth only; injectable transport
│   ├── Response.php          ← status, JSON, result(), error()
│   ├── ApiException.php      ← Cloudflare's message + a hint
│   ├── Options.php           ← options and environment lookup
│   ├── Diagnostics.php       ← the pass/fail check (token, zone, probe purge)
│   ├── Purger.php            ← page → URLs, purge-on-save
│   ├── Api/                  ← one class per API gate (extends Endpoint)
│   ├── Licensing/            ← LicenseManager, Provider interface, Polar adapter
│   └── Panel/                ← Guard (permissions, error mapping), View (tabs)
├── config/
│   ├── api.php               ← Panel API routes, /api/cloudflare-tools/…
│   ├── areas.php             ← the Panel area, page button, purge dialog
│   ├── commands.php          ← CLI / Janitor commands
│   └── hooks.php             ← purge-on-save
├── src/                      ← Panel Vue source (built into index.js)
├── composer.json             ← plugin manifest only
└── dev/
    ├── composer.json         ← getkirby/cms + cli, pestphp/pest, kirby-helpers
    ├── tests/                ← Pest suite + FakeCloudflare
    ├── index.php  site/      ← visual dev site (plugin symlinked in)
    └── kirby/  vendor/       ← generated, gitignored
```

`dev/` and `src/` are `export-ignore`d, so `composer require` never ships them.

## Names

Following Kirby's [publishing guide](https://getkirby.com/docs/guide/plugins/publishing),
the plugin has three names on purpose. The `kirby-` prefix helps on GitHub,
Packagist and Polar, where the plugin sits among packages for every
framework; inside Kirby it would only be noise.

| Where | Name |
|---|---|
| GitHub repository | `ianhobbs/kirby-cloudflare-tools` |
| Composer / Packagist package | `ianhobbs/kirby-cloudflare-tools` |
| Polar product | `kirby-cloudflare-tools` |
| `Kirby::plugin()` and `panel.plugin()` | `ianhobbs/cloudflare-tools` |
| Install folder (`extra.installer-name`) | `site/plugins/cloudflare-tools` |
| Options | `ianhobbs.cloudflare-tools.*` |
| Panel area, API routes, dialogs | `cloudflare-tools`, `/api/cloudflare-tools/…` |
| Plugin assets | `/media/plugins/ianhobbs/cloudflare-tools/…` |
| PHP namespace | `IanHobbs\CloudflareTools` |

Everything Kirby sees comes from the plugin name, so renaming the repository
or package never changes a customer's config keys, URLs or licence file.
`extra.kirby.compatible` in composer.json tells Kirby's plugin directory
which Kirby versions to list the plugin for.

## Setup

```bash
cd dev
composer install
composer test
```

PHP 8.3+, Composer. Node 18+ only to change the Panel UI.

## Tests

```bash
cd dev
composer test
composer test:coverage   # needs xdebug or pcov
```

Nothing reaches Cloudflare. `dev/tests/FakeCloudflare.php` is plugged in as
the client's transport through the plugin's undocumented `transport` option;
each test registers the replies it needs and inspects what was sent:

```php
$fake = fake()->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

cloudflare()->cache()->purgeUrls(['https://example.com/a']);

expect($fake->last()['body'])->toBe(['files' => ['https://example.com/a']]);
```

`fake()` boots a fresh Kirby with a new fake; `api()` calls a Panel API
route as a user (`kirby` is an admin; `editor@example.com` has the plugin's
default permissions; `client@example.com` has no access to the area).

| File | Covers |
|---|---|
| `PluginTest` | Registration, version, area/routes/commands/permissions, environment lookup |
| `ClientTest` | Auth headers, bodies, transport failures, error hints, GraphQL errors, credential-free probe |
| `CloudflareTest` | Zone ID validation, domain → zone lookup, `{zone}`/`{account}`, custom gates |
| `CacheTest` | Every purge type, batching, validation, Tiered Cache |
| `SettingsTest` | Panel field building, value conversion, per-setting save results |
| `SecurityTest` | Access rule targets, bot config merge, Under Attack restore |
| `DnsTest` | Pagination, input cleaning, proxy switch |
| `AnalyticsTest` | Normalising the GraphQL payload, query literal validation |
| `DiagnosticsTest` | The full check, refused purges, wrong zones, probe states |
| `PurgerTest` | Page URLs, `pageUrls`, purge-on-save hooks, failures not blocking saves |
| `PanelApiTest` | Routes as admin/editor/no-access users, error mapping, DNS write gate, purge dialog |
| `LicenseTest` | Development hosts, activation, refusals, daily checks, the 14-day grace, removal, the dialog, no key in the Panel |

**Not covered:** the real Cloudflare API (check by hand with a token, below),
and the Vue components (check in the dev site).

## The visual dev site

```bash
cd dev
composer start          # php -S localhost:8000 kirby/router.php
```

Open `http://localhost:8000/panel`, create an account, add a page.

The dev site loads its secrets the way the README tells users to: from
`glue/.env` at the repo root, one level above `dev/` (the dev site's web
root). `glue/` is gitignored. To try it against a real zone:

```dotenv
# glue/.env
CLOUDFLARE_API_TOKEN=…
CLOUDFLARE_ZONE_ID=…
# CLOUDFLARE_DNS_MODE=write
```

Use a test zone, or a token without Edit permissions, while developing.

`dev/site/plugins/cloudflare-tools` is a relative symlink to the repo root,
so the plugin loads exactly as on a real site. The dev site doesn't use
Composer's autoloader for the plugin, so it also exercises the fallback
autoloader in `index.php`.

## Panel bundle

```bash
npm install
npm run build     # src/ → index.js + index.css
npm run dev       # kirbyup dev server with hot reload
```

Built with kirbyup against the Panel's own Vue 2.7. Commit `index.js` and
`index.css` after every change under `src/`: a Composer install has no
build step.

The tabs only call `cloudflare-tools/…` API routes through `this.$api`,
which adds the Panel's session and CSRF token. Never pass the token to the
browser, not even masked.

## Where it came from

The plugin merges two site-local Cloudflare plugins and generalises them.
These are the API calls and patterns they used, and where each lives now.

**A site-local purge plugin** (Janitor buttons on the Site page):

| Used | Now |
|---|---|
| `POST zones/:zone/purge_cache` — `purge_everything`, and `files` for one probe file | `Api\Cache` (+ prefixes, hosts, tags) |
| `GET user/tokens/verify`, falling back to `GET accounts` → `accounts/:id/tokens/verify` for account-owned tokens | `Api\Token::verify()` |
| `GET zones/:zone`, zone name checked against the site's host | `Diagnostics::checkZone()` |
| `cf-cache-status` / `Age` headers on a probe file, before and after a purge | `Diagnostics::warmProbe()` / `checkProbe()` |
| Kirby pages cache flushed before purging everything | `Diagnostics::purgeAll()`, `flushKirby` option |
| Error code hints (1000, 6003, 6111, 7000, 7003, 10000) | `ApiException::HINTS`, plus IP-filter and 429 hints |
| `cloudflare:check`, `cloudflare:purge` commands with `Janitor::ARGS` | `config/commands.php` (Janitor optional) |
| Token + zone in `glue/.env`, loaded by kirby-helpers' `Env::load()` | Same pattern, documented generically in the README |

**klub/cloudflare** (traffic for members, per-member domain):

| Used | Now |
|---|---|
| `GET zones?name=…&status=active` — domain → zone ID, cached | `Api\Zone::resolve()` (now walks up subdomains), `domain` option, `forDomain()` |
| GraphQL `httpRequests1dGroups` — requests, pageViews, bytes, threats, cachedRequests, cachedBytes, responseStatusMap, countryMap, uniques | `Api\Analytics`, same output shape |
| Lowercase GraphQL scalars → validated, inlined literals | `Analytics::trafficQuery()` |
| Bearer token or legacy email + Global API Key | `Client` |
| Results in the Kirby plugin cache | `Cloudflare::cached()` |
| `site()->cloudflare($config)` factory with overrides | Same site method |

klub/cloudflare could now depend on this package and keep only its members
route and snippet: `site()->cloudflare()->forDomain($domain)->analytics()->traffic($days)`
returns the same shape its snippet renders.

**slider-block** (not a Cloudflare plugin) supplied the packaging: the
Composer installer setup, the `dev/` environment with Pest and a symlinked
dev site, kirbyup, `export-ignore`, and the release checklist below.

## Adding an API gate

1. Read the endpoint in Cloudflare's API docs, and check it is available on
   the plans you target.
2. Try it from the command line before writing code:

   ```bash
   cd dev
   vendor/bin/kirby cloudflare:api GET 'zones/{zone}/email/routing'
   ```

3. Add `classes/Api/YourGate.php` extending `Endpoint`. Use
   `$this->zonePath(…)` and `->result()`; validate input before it leaves
   the server; normalise the answer into a flat array.
4. Register it in `Cloudflare::$endpoints`, and add an `@method` line to the
   class docblock for IDE support.
5. Test it with `FakeCloudflare`.
6. For the Panel: add a route in `config/api.php` wrapped in
   `Guard::run('<permission>', …)`, a tab in `Panel\View::TABS`, and a
   component in `src/components/tabs/`. Reads that a token may lack
   permission for go through `$attempt`, so one refusal doesn't blank the
   whole tab.
7. Document the token permission it needs in the README.

A site can do steps 3 and 4 without touching the plugin, through the
`endpoints` option.

### Roadmap (Free plan)

| Gate | Endpoint | Notes |
|---|---|---|
| WAF custom rules | `zones/:zone/rulesets/phases/http_request_firewall_custom/entrypoint` | 5 rules on Free. Cloudflare now recommends these over IP Access Rules. Zone WAF Edit |
| Redirect rules | `…/phases/http_request_dynamic_redirect/entrypoint` | 10 on Free. Single Redirect Edit |
| Cache rules | `…/phases/http_request_cache_settings/entrypoint` | 10 on Free. Cache Rules Edit |
| Turnstile | `accounts/:account/challenges/widgets` | Account-level; needs `CLOUDFLARE_ACCOUNT_ID` |
| Email Routing | `zones/:zone/email/routing`, `…/rules` | |
| Web Analytics (RUM) | `accounts/:account/rum/site_info/list` | Account-level |
| AI bot policies | `zones/:zone/bot_management` | Cloudflare is replacing the single "Block AI bots" setting with per-behaviour policies (September 2026); revisit `Security::setAiBotsProtection()` |

## Licensing

How the licence is sold is in VENDOR-STRATEGY.md (kept outside the repository); the
terms are in `LICENSE`. In the code:

- `Licensing\LicenseManager` works out the status (`demo`, `missing`,
  `active`, `inactive`, `unknown`), keeps the seller's last answer in
  `site/config/.cloudflare-tools-license`, and builds the
  `Kirby\Plugin\License` that `index.php` hands to Kirby for the System view.
  Reading the status never makes a network call.
- `Licensing\Provider` is the seller interface; `Licensing\Polar` is the
  only adapter. Swapping seller means a new adapter and changing
  `LicenseManager::fromOptions()`.
- `config/areas.php` has the admin-only `cloudflare-tools.license` dialog that
  the System view's status opens. `config/api.php`'s `status` route runs the
  daily `refresh()`; it is the only place a check happens on its own.
- `config/commands.php` has `cloudflare:license`.

**Switching checks on:** set `Polar::ORGANIZATION` to the organisation ID
from Polar (Settings › General). Until then every key is saved and shown as
"Not verified".

**Testing against Polar's sandbox,** without changing the constant:

```php
// dev/site/config/config.php
'ianhobbs.cloudflare-tools' => [
    'licenseServer' => [
        'organization' => 'your-sandbox-organization-id',
        'endpoint'     => \IanHobbs\CloudflareTools\Licensing\Polar::SANDBOX,
    ],
],
```

The dev site at `localhost` counts as a development site, so it won't
activate. Add a hosts-file entry (for example `cloudflare-tools.example`
pointing at 127.0.0.1) and set Kirby's `url` option to it to test the public
path.

## Release checklist

Selling the plugin (the licence model, seller, tiers, and what must change
before the first public push) is covered in
VENDOR-STRATEGY.md (kept outside the repository).

1. Update the version in `index.php` (`version:`), `package.json`, and
   `dev/tests/Feature/PluginTest.php`; add a `CHANGELOG.md` entry.
2. `cd dev && composer test` — everything passes.
3. `npm run build` if anything under `src/` changed, and commit `index.js`
   and `index.css`.
4. Check the README's options and permissions tables against `index.php`
   and `Panel\Guard`.
5. Commit, **then** tag, so the tag contains the version bump:

   ```bash
   git tag -a vx.y.z -m "vx.y.z — summary"
   git push origin main --follow-tags
   ```

6. Check what ships: `git archive --format=tar vx.y.z | tar -t` lists no
   `dev/` or `src/`.

Don't add a `"version"` field to `composer.json`; Packagist reads versions
from tags. Submit the package to Packagist once, after the first tag.

## Kirby 6

The current line targets Kirby 5 (Vue 2 Panel) and is pinned with
`"conflict": {"getkirby/cms": "<5.0.0 || >=6.0.0"}`. Kirby 6 moves the Panel
to Vue 3; that is a major version of this plugin with a newer kirbyup. The
components use the Options API and core `k-*` components only, so the port
should mostly be verification.
