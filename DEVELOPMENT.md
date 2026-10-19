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

Following Kirby's [publishing](https://getkirby.com/docs/guide/plugins/publishing)
and [packaging](https://getkirby.com/docs/guide/plugins/packaging) guides,
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
| CLI commands | `cloudflare-tools:check`, `:purge`, `:api`, `:license` |
| Page view button | `cloudflare-purge` |
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
| `cloudflare:check`, `cloudflare:purge` commands with `Janitor::ARGS` | `cloudflare-tools:check`, `cloudflare-tools:purge` in `config/commands.php` (Janitor optional) |
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
   vendor/bin/kirby cloudflare-tools:api GET 'zones/{zone}/email/routing'
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
- `config/commands.php` has `cloudflare-tools:license`.

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
7. Create a GitHub Release from the tag, so the README's download link has
   an archive to offer. Mark betas as pre-releases:

   ```bash
   gh release create vx.y.z --verify-tag --title "vx.y.z" --notes "…"   # add --prerelease for betas
   ```

Don't add a `"version"` field to `composer.json`; Packagist reads versions
from tags. ZIP and submodule installs get the version from
`Kirby::plugin(version: …)` in `index.php`, which takes precedence over
composer.json (see the packaging guide). Submit the package to Packagist
once, after the first tag.

## Kirby 6 (2.x)

1.x targets Kirby 5 (Vue 2 Panel) and is pinned with
`"conflict": {"getkirby/cms": "<5.0.0 || >=6.0.0"}`. Kirby 6 moves the Panel
to Vue 3 and needs PHP 8.3+, so it gets its own major version. One Panel
bundle can't serve Vue 2 and Vue 3, so no release spans Kirby 5 and 6.
Kirby 4 is not supported (a Kirby 4 lite edition was tried and dropped; the
work is kept at the tag `archive/kirby4-lite`).

ZIP and submodule installs never see the `conflict` rule, so `index.php`
asks `Compat::supported()` first: on any Kirby major but `Compat::KIRBY`
it registers nothing and logs `Compat::message()`. `classes/Compat.php` is
the only place that checks the Kirby version. `Compat::KIRBY` is 5 on
`main` and 6 on `feature/kirby6`; keep it 6 when merging `main` in.

### Branches

- `main` stays 1.x (Kirby 5) and takes 1.x fixes until 2.0.0 ships.
- `feature/kirby6` is the 2.x line. Work on it in a second worktree, so
  each checkout keeps its own `dev/vendor` (Kirby 5 in one, Kirby 6 in the
  other):

  ```bash
  git worktree add -b feature/kirby6 ../kirby-cloudflare-tools-kirby6 main
  ```

- 2.0.0 betas are tagged on `feature/kirby6` and marked as pre-releases.
- When Kirby 6.0.0 is stable and 2.0.0 is ready: branch `1.x` from `main`,
  merge `feature/kirby6` into `main`, tag 2.0.0.

### The Kirby 6 dev environment

On `feature/kirby6`, `dev/composer.json` installs the Kirby 6 beta
(`minimum-stability: beta`), Pest 4 (PHP 8.3+) and
[Kirby MCP](https://github.com/bnomei/kirby-mcp) 6.x, whose knowledge base
and skills follow Kirby 6 (Kirby MCP 1.x is the Kirby 5 line):

```bash
cd dev
composer update -W
vendor/bin/kirby-mcp install --project="$PWD"   # runtime commands + adapter, gitignored
claude mcp add kirby -- "$PWD/vendor/bin/kirby-mcp" "--project=$PWD"
```

Run `claude mcp add` from the worktree root with absolute paths, so the
server belongs to that folder only. After `composer update
bnomei/kirby-mcp`, run `vendor/bin/kirby-mcp update`.

The Panel bundle needs kirbyup 4 (pre-release under the `next` tag),
which needs Node 24+:

```bash
nvm use 24
npx kirbyup@next src/index.js       # build
npx kirbyup@next dev src/index.js   # dev server; writes index.dev.js
```

Kirby 6 loads `index.dev.js` instead of `index.js` when it exists (Kirby 5
used `index.dev.mjs`). It is gitignored and must never ship.

### Status on 6.0.0-beta.1 (checked 2026-10-19)

- **Pest suite:** 130 of 148 pass. All 18 failures have one cause,
  `Panel::hasAccess()` in `Panel\Guard`, which Kirby 6 removed. With that
  call replaced, all 148 pass.
- **Panel bundle:** builds with kirbyup 4.0.0-beta.1 without changes
  (33 kB). Not yet checked in the browser.
- **Dev site:** boots; the Panel install screen loads.

### Port checklist

PHP:

1. `Panel\Guard`: replace `Panel::hasAccess($user, self::AREA)` (lines 48
   and 93). Kirby 6's `$kirby->panel()->access()->area($area)` only checks
   the logged-in user, but `Guard::can()` takes any `$user`, so read that
   user's `access` permissions directly, or drop the `$user` parameter.
2. Trusted HTML: Kirby 6 escapes plain strings in dialog text and in
   `k-box`, `k-text`, `k-item` and `k-tag`. `config/areas.php` builds HTML
   for the licence dialog's info text (line 81 on) and the purge dialog
   (line 178 on): wrap it in `Kirby\Toolkit\HtmlString`.
3. `composer.json`: `"php": ">=8.3"`,
   `"conflict": {"getkirby/cms": "<6.0.0-beta.1 || >=7.0.0"}`,
   `extra.kirby.compatible: "^6.0"` (also matches the betas), keyword
   `kirby6` in place of `kirby5`.
4. Version guard: done. `Compat::KIRBY` is 6, so a 2.x copy on a Kirby 5
   site switches itself off and its log message points to 1.x.
5. Exceptions already use named arguments (Kirby 6 dropped the array form)
   and view buttons are declared as arrays in the area, so neither needs
   changes. Check again after each Kirby beta.

Panel (Vue 3):

1. `package.json`: `kirbyup` `^4` (the `next` tag until it is stable),
   `engines.node` `>=24`, drop the Vite 6 override; add an `.nvmrc`.
2. `DnsTab.vue` line 6: `v-model` on a `k-*-input` silently stops working
   in Vue 3. Bind `:value` and `@input` as the other tabs do.
3. Trusted HTML: the confirm dialogs in `DnsTab.vue` (line 192) and
   `SecurityTab.vue` (line 199) pass HTML as text. Mark it with `$h()`.
4. Check every tab, the page Purge button and the licence dialog in the dev
   site with the browser console open: Kirby logs components it can't
   register, and kirbyup's dev mode adds Vue's warnings.
5. Every component that emits declares `emits` already; keep it that way
   (undeclared listeners land on the root element in Vue 3).

Kirby 6 features to look at once the port works:

- **Content negotiation:** Kirby 6 can serve a page's Markdown or JSON
  representation for the same URL, chosen by the `Accept` header.
  Cloudflare's cache doesn't vary on `Accept`, so a cached HTML page could
  be served to a client asking for JSON, or the other way round. Find out
  what Kirby sends (`Vary`, cache headers) and document it; maybe warn in
  Diagnostics when it is on.
- **Buttons field:** a blueprint field that opens a dialog. A ready-made
  "Purge this page" button for blueprints would suit editors.
- **Live editor:** check how purge-on-save behaves while editors type in
  the new live preview (it should still fire only on save).

Licence:

1. 2.x needs a new licence: Polar has no upgrade option, so 2.x is its own
   Polar product with its own licence-key benefit, and 1.x keys must not
   activate it.
2. `Licensing\Polar`: add the 2.x benefit ID as a constant (overridable
   through `licenseServer`, for the sandbox). Pass it as `benefit_id` to
   `validate`. `activate` takes no `benefit_id` but returns the key's, so
   compare it; for a key from another product, deactivate it straight away
   and refuse it with a message that a Kirby 6 licence is needed.
3. Tests for a 1.x key refused on activation and on the daily check.

CI and release:

1. `main` has no CI yet. `.github/workflows/tests.yml` in
   `archive/kirby4-lite` is a starting point: on `main` run Kirby 5 · PHP
   8.2 and 8.5, on `feature/kirby6` the Kirby 6 beta · PHP 8.3 and 8.5.
2. Tag 2.0.0-beta.1 once the checklist above passes on the Kirby beta.
   Re-test on every Kirby beta and RC; Kirby plans 6.0.0 before the end of
   2026.
3. README: supported Kirby versions at the top, an upgrade note from 1.x,
   and which plugin line to install for Kirby 5 and Kirby 6.
