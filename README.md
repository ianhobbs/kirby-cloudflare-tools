# Cloudflare Tools for Kirby

**For Kirby 5** · PHP 8.3+ · any Cloudflare plan, including Free

A Cloudflare control panel inside the Kirby Panel. Editors purge the cache,
switch Development Mode or "I'm Under Attack", and read their traffic;
admins manage zone settings, bots, IP rules, SSL/TLS and DNS. All of it
works on Cloudflare's **Free plan**.

- **Cache** — purge everything, or by URL, prefix, hostname or cache tag; a
  **Purge** button on every page; optional purge-on-save; Tiered Cache.
- **Zone settings** — caching level, browser TTL, Always Online, security
  level, SSL mode, Always Use HTTPS, minimum TLS, HTTP/3, Early Hints and more.
- **Security** — I'm Under Attack, Bot Fight Mode, Block AI bots, IP Access
  Rules (block, challenge or allow an IP, range, country or ASN).
- **SSL/TLS** — edge certificate status and expiry.
- **DNS** — list records, switch the proxy, and (opt-in) add, edit and delete.
- **Traffic** — page views, visitors, requests, bandwidth, cache hit rate,
  error rate, threats and top countries, from Cloudflare's analytics.
- **Diagnostics** — checks the token, the zone, and that a purge really
  reaches the edge.
- **CLI and Janitor** — `cloudflare-tools:check`,
  `cloudflare-tools:purge`, and `cloudflare-tools:api` for calling any API
  endpoint.
- **An open PHP API** — `site()->cloudflare()` for your own templates, hooks
  and plugins, with room to add the parts of Cloudflare's API it doesn't
  cover yet.

The API token never reaches the browser. The Panel talks to the plugin's own
API routes, which need a logged-in Panel user, and the plugin talks to
Cloudflare from the server.

## Requirements

- Kirby 5
- PHP 8.3 or later, with cURL. 8.3 is the minimum: Composer refuses to
  install the plugin on older versions.
- A domain on Cloudflare (any plan)

## Installation

```bash
composer require ianhobbs/kirby-cloudflare-tools
```

Composer puts the plugin in `site/plugins/cloudflare-tools/`.

Without Composer, download the release archive from
[GitHub](https://github.com/ianhobbs/kirby-cloudflare-tools/releases), unzip
it, and rename the folder to `site/plugins/cloudflare-tools/`.

Or add it as a Git submodule:

```bash
git submodule add https://github.com/ianhobbs/kirby-cloudflare-tools.git site/plugins/cloudflare-tools
```

## Credentials

The plugin needs two values from Cloudflare:

| What | Environment variable | Where to find it |
|---|---|---|
| **API token** (the auth) | `CLOUDFLARE_API_TOKEN` | Cloudflare dashboard › *My Profile* › *API Tokens* › *Create Token* › *Create Custom Token* |
| **Zone ID** (the domain's ID) | `CLOUDFLARE_ZONE_ID` | Your domain's *Overview* page, *API* box on the right. 32 characters, letters a–f and digits |

Without both, the Cloudflare area shows a "Connect Cloudflare" notice and
nothing is sent to Cloudflare.

### Token permissions

Give the token only what you'll use, and limit it to your domain (*Zone
Resources › Include › Specific zone › your domain*).

| Feature | Permission |
|---|---|
| Purging, the page Purge button, purge-on-save, Diagnostics | Zone › **Cache Purge** › Purge |
| Overview (domain, plan, name servers), Diagnostics | Zone › **Zone** › Read |
| Pause / resume Cloudflare | Zone › **Zone** › Edit |
| Settings tabs | Zone › **Zone Settings** › Read |
| Changing settings, Development Mode, Under Attack, Tiered Cache | Zone › **Zone Settings** › Edit |
| Traffic | Zone › **Analytics** › Read |
| Bot Fight Mode, Block AI bots | Zone › **Bot Management** › Edit |
| IP Access Rules | Zone › **Firewall Services** › Edit |
| Edge certificates | Zone › **SSL and Certificates** › Read |
| DNS records | Zone › **DNS** › Read (Edit to change records) |

A tab whose permission the token lacks shows Cloudflare's error in place of
its data; the rest of the panel keeps working.

If you set *Client IP Address Filtering* on the token, include your web
server's IP address, because the server makes the calls, not your browser.

### Keeping the token out of the web root

**Never put the token in `config.php` or anywhere in your repository.** Keep
it in a `.env` file in a folder **outside the web root**, so the web server
can never serve it, and load that file from `site/config/config.php`.

Folder names differ from site to site. Below, `secrets/` stands for whatever
you call that folder, and `public/` for your web root:

```text
your-project/
├── secrets/              ← outside the web root: never served, never committed
│   └── .env
└── public/               ← the web root (where Kirby's index.php is)
    └── site/
        └── config/
            └── config.php
```

`secrets/.env`:

```dotenv
CLOUDFLARE_API_TOKEN=your-scoped-api-token
CLOUDFLARE_ZONE_ID=your-32-character-zone-id
```

Load it with [kirby-helpers](https://github.com/johannschopplich/kirby-helpers)
(`composer require johannschopplich/kirby-helpers`), at the top of
`site/config/config.php`:

```php
<?php

// Secrets live outside the web root. dirname(__DIR__, 3) goes up from
// site/config/ past the web root; change the 3 and the folder name to
// match your layout.
$secrets = dirname(__DIR__, 3) . '/secrets';

if (is_file($secrets . '/.env')) {
    \JohannSchopplich\Helpers\Env::load($secrets);
}

return [
    'ianhobbs.cloudflare-tools' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone'  => env('CLOUDFLARE_ZONE_ID'),
    ],
];
```

The `return` block is optional. The plugin reads `CLOUDFLARE_API_TOKEN` and
`CLOUDFLARE_ZONE_ID` from the environment itself, so the same names work
when your host sets real environment variables (Docker, Forge, a PHP-FPM
pool) and there is no `.env` file at all.

Then make sure the file stays private:

- **Git** — add the folder to `.gitignore` (`/secrets/`).
- **Deploys** — exclude it from rsync, FTP sync or your deploy tool, so a
  deploy neither uploads your local copy nor deletes the one on the server.
  With `rsync --delete`, an unlisted folder is deleted on every push.
- **The server** — create the file there once, over SSH or your host's file
  manager, and make it readable only by your user: `chmod 600 secrets/.env`.
- **Check it** — a folder put inside the web root by mistake can be
  downloaded. Open `https://your-domain/secrets/.env` in a browser: you
  should get a 404, not your token.

Other credential options, also read from the environment:

| Variable | Use |
|---|---|
| `CLOUDFLARE_DOMAIN` | Find the zone from a domain instead of a Zone ID. Needs Zone › Zone › Read. A subdomain finds its parent zone. |
| `CLOUDFLARE_ACCOUNT_ID` | Only for account-owned tokens, and for account-level API calls. |
| `CLOUDFLARE_API_EMAIL` + `CLOUDFLARE_API_KEY` | Legacy Global API Key. It can do anything on your account, so use a scoped token instead. |

## The Panel

A **Cloudflare** entry appears in the Panel menu, with these tabs:

| Tab | What it does |
|---|---|
| Overview | Domain status and plan, quick actions (purge everything, Development Mode, I'm Under Attack, pause), traffic charts, name servers |
| Caching | Custom purges, cache settings, Tiered Cache |
| Security | Security settings, Bot Fight Mode, Block AI bots, IP Access Rules |
| SSL/TLS | Encryption mode, HTTPS and TLS settings, edge certificates |
| Speed & Network | HTTP/3, 0-RTT, Early Hints, Rocket Loader, IPv6, WebSockets… |
| DNS | Records and the proxy switch; read-only unless you allow writes |
| Diagnostics | A connection check that purges only one small probe file |

Only settings Cloudflare reports for your zone are shown. Settings your plan
can't change appear switched off.

### The page Purge button

Add `cloudflare-purge` to a page blueprint's buttons:

```yaml
# site/blueprints/pages/article.yml
buttons:
  - open
  - preview
  - "-"
  - settings
  - status
  - cloudflare-purge
```

or to every page at once, in `config.php`:

```php
'panel' => [
    'viewButtons' => [
        'page' => ['open', 'preview', '-', 'settings', 'languages', 'status', 'cloudflare-purge'],
    ],
],
```

It opens a dialog listing the URLs it will purge: the page in every
language, its parent pages, and the home page.

### Who may do what

The plugin uses Kirby's role permissions. Admins can always do everything.
Other roles get these defaults:

| Permission | Default | Covers |
|---|---|---|
| `analytics` | yes | Traffic charts |
| `purge` | yes | Purging, the page Purge button, Diagnostics |
| `settings` | no | Zone settings, Development Mode, Tiered Cache, pause |
| `security` | no | Security settings, I'm Under Attack, bots, IP Access Rules |
| `dns` | no | Changing DNS records (when DNS writes are allowed) |

Without a permission, a role sees that part of the panel read-only; without
`analytics`, the traffic charts are hidden. Change the defaults in the
role's blueprint:

```yaml
# site/blueprints/users/editor.yml
title: Editor
permissions:
  access:
    cloudflare-tools: true   # false hides the Cloudflare area entirely
  ianhobbs.cloudflare-tools:
    purge: true
    settings: true
    security: false
```

## Options

All options go under `ianhobbs.cloudflare-tools` in `site/config/config.php`.
Each one may be a closure, which the plugin calls when it needs the value.

| Option | Default | |
|---|---|---|
| `token`, `zone`, `domain`, `account`, `email`, `key` | from the environment | See [Credentials](#credentials) |
| `dns` | `'read'` | `'write'` allows adding, editing and deleting records; `false` removes the DNS tab |
| `autoPurge` | `false` | Purge a page's URLs whenever it is saved, published, renamed, sorted or deleted, and when its files change |
| `autoPurgeSite` | `true` | With `autoPurge` on, purge everything when the site's own fields change |
| `purgeParents` | `true` | Include parent pages and the home page in a page's purge |
| `pageUrls` | `null` | `fn (Kirby\Cms\Page $page): array` — replaces the URL list for a page (add feeds, tag pages…) |
| `flushKirby` | `true` | Flush Kirby's pages cache before purging everything |
| `probe` | site's `/favicon.ico` | A small file Cloudflare caches, used to check that a purge reaches the edge |
| `cache` / `cacheTtl` | `true` / `10` | Caches the zone lookup and traffic (minutes; `0` turns it off) |
| `defaultDays` | `7` | Traffic range the Panel opens with (1–30) |
| `panel.label`, `panel.icon` | `'Cloudflare'`, `'cloud'` | Menu entry |
| `panel.menu` | `true` | `false` hides the menu entry (the area still works by URL) |
| `panel.hide` | `[]` | Tabs to leave out, e.g. `['dns', 'speed']` |
| `endpoints` | `[]` | Extra API gates, see [Adding an API gate](#adding-an-api-gate) |
| `timeout` | `15` | Seconds per API request |
| `license` | `CLOUDFLARE_TOOLS_LICENSE` | Your licence key, see [Activating a licence](#activating-a-licence) |
| `licenseIgnore` | `[]` | More development hosts that need no licence, e.g. `['staging.*']` |

Purging needs full URLs. Pages get them from the Panel, which knows your
domain; on the command line, set Kirby's `url` option so they do there too.

## Command line and Janitor

With [Kirby CLI](https://github.com/getkirby/cli):

```bash
vendor/bin/kirby cloudflare-tools:check   # token, zone, and a purge of the probe file only
vendor/bin/kirby cloudflare-tools:purge   # Kirby's page cache, then the whole Cloudflare cache
vendor/bin/kirby cloudflare-tools:api GET 'zones/{zone}/settings/http3'
vendor/bin/kirby cloudflare-tools:api PATCH 'zones/{zone}/settings/http3' --body '{"value":"on"}'
vendor/bin/kirby cloudflare-tools:license [status|activate KEY|check|remove]
```

`cloudflare-tools:api` fills in `{zone}` and `{account}` and prints Cloudflare's
JSON answer.

With [Janitor](https://github.com/bnomei/kirby-janitor) installed,
`cloudflare-tools:check` and `cloudflare-tools:purge` also work as Panel
buttons:

```yaml
# site/blueprints/site.yml
fields:
  cloudflare:
    type: janitor
    command: 'cloudflare-tools:purge'
    label: Purge Cloudflare
    icon: cloud
    confirm: Purge the whole Cloudflare cache?
    progress: Purging…
    cooldown: 5000
  cloudflareCheck:
    type: janitor
    command: 'cloudflare-tools:check'
    label: Test Cloudflare
    icon: check
    progress: Testing…
    cooldown: 5000
```

## Purge on save

```php
'ianhobbs.cloudflare-tools' => [
    'autoPurge' => true,
],
```

Saving, publishing, renaming or sorting a page then purges its URLs (and its
parents' and the home page's). A renamed page also purges its old URL.
Changing a file purges its page. Changing the site's own fields purges
everything, as those usually appear on every page.

Creating a draft purges nothing. If Cloudflare can't be reached, the purge is
logged and the save goes through.

Free accounts may send 5 hostname, tag, prefix or purge-everything requests a
minute, shared by all Free zones on the account.

## PHP API

```php
$cf = site()->cloudflare();

$cf->cache()->purgeUrls([$page->url()]);
$cf->cache()->purgePrefixes(['example.com/blog']);
$cf->cache()->purgeTags(['products']);
$cf->cache()->purgeEverything();

$cf->settings()->value('ssl');                 // 'strict'
$cf->settings()->set('development_mode', 'on');
$cf->security()->underAttack(true);
$cf->security()->addAccessRule('block', '203.0.113.7', notes: 'spam');
$cf->dns()->records(['type' => 'A']);
$cf->analytics()->traffic(7);                 // totals, daily series, countries
$cf->zone()->summary();
$cf->token()->verify();
```

The same connector can serve other zones with the same token, for example
one per client domain:

```php
site()->cloudflare()->forDomain($user->domain()->value())->analytics()->traffic(30);
site()->cloudflare(['zone' => '…', 'token' => '…']);  // other credentials
```

Failures throw `IanHobbs\CloudflareTools\ApiException`, whose message is
Cloudflare's own plus a hint for the usual mistakes:

```php
try {
    $cf->cache()->purgeEverything();
} catch (\IanHobbs\CloudflareTools\ApiException $e) {
    // "Authentication error (10000), token is wrong, lacks the permission…"
}
```

### Calling any endpoint

For parts of the API without a method yet, `request()` sends any call and
fills in `{zone}` and `{account}`:

```php
$response = site()->cloudflare()->request('GET', 'zones/{zone}/email/routing');

$response->ok();      // bool
$response->result();  // the `result` payload, or throws ApiException
$response->error();   // Cloudflare's message, with a hint
```

### Adding an API gate

Each area of the API is a class extending `Api\Endpoint`. Add your own the
same way:

```php
use IanHobbs\CloudflareTools\Api\Endpoint;

class EmailRouting extends Endpoint
{
    public function rules(): array
    {
        return $this->client()->get($this->zonePath('email/routing/rules'))->result();
    }
}
```

Register it in `config.php`:

```php
'ianhobbs.cloudflare-tools' => [
    'endpoints' => ['emailRouting' => EmailRouting::class],
],
```

and call it like the built-in ones: `site()->cloudflare()->emailRouting()->rules()`.

## What it covers

| Area | Cloudflare endpoints | Free plan |
|---|---|---|
| Purge | `POST zones/:zone/purge_cache` (everything, files, prefixes, hosts, tags) | yes, all methods |
| Zone | `GET/PATCH zones/:zone`, `GET zones?name=` | yes |
| Settings | `GET zones/:zone/settings`, `GET/PATCH zones/:zone/settings/:id` | yes |
| Tiered Cache | `zones/:zone/argo/tiered_caching`, `zones/:zone/cache/tiered_cache_smart_topology_enable` | yes |
| Bots | `GET/PUT zones/:zone/bot_management` | Bot Fight Mode, Block AI bots |
| IP Access Rules | `zones/:zone/firewall/access_rules/rules` | yes |
| SSL/TLS | `zones/:zone/ssl/universal/settings`, `…/ssl/certificate_packs`, `…/ssl/verification` | yes |
| DNS | `zones/:zone/dns_records` | yes |
| Analytics | GraphQL `httpRequests1dGroups` | yes |
| Token | `user/tokens/verify`, `accounts/:account/tokens/verify` | yes |

Planned for later releases, all available on the Free plan: WAF custom rules,
redirect rules, cache rules, Turnstile widgets, Email Routing and Web
Analytics. Until then, `request()` and `cloudflare-tools:api` reach them.

## Troubleshooting

| Message | Usually means |
|---|---|
| `… (1000), token not recognised` | The token is wrong or was deleted. |
| `… (6003)` or `(6111)`, *malformed* | A stray space, quote or line break around the token in `.env`. |
| `… (7003), check CLOUDFLARE_ZONE_ID` | The Zone ID is wrong, or belongs to another account. |
| `… (10000), token is wrong, lacks the permission…` | Add the permission the feature needs (see [Token permissions](#token-permissions)), or include this zone in the token's zone resources. |
| `Cannot use the access token from location` | The token's IP filter doesn't include your web server. |
| `rate limiting this account` | Too many purges in a minute. Wait and try again. |
| Purge works but visitors still see old pages | Check the browser cache: Cloudflare can't clear what browsers kept. Look at your HTML's `Cache-Control` header. |

**Diagnostics** (or `vendor/bin/kirby cloudflare-tools:check`) runs through
all of this in order, and only purges the probe file.

## Development

See [DEVELOPMENT.md](DEVELOPMENT.md) in the repository.

## License

Cloudflare Tools is commercial software with public source code, licensed
like Kirby itself:

- **Free** on development and staging sites (local computers, private
  networks, password-protected staging), for as long as you like.
- **One paid licence activation per public website.** Licences come in tiers
  by number of sites.
- You may change the code for your own sites, but not redistribute or
  resell it.

The full terms are in [LICENSE](LICENSE). Kirby itself needs its own licence.

### Activating a licence

Activate the key on the public website, after it goes live. Any of these
works:

- **In the Panel**: *System* › *Plugins* › click the licence status next to
  Cloudflare Tools (admins only). The Cloudflare area's Overview tab links
  there too.
- **In the environment**: add the key to the same `.env` file as your
  Cloudflare token. The plugin activates it the next time an admin or
  editor opens the Cloudflare area:

  ```dotenv
  CLOUDFLARE_TOOLS_LICENSE=your-licence-key
  ```

- **On the command line**: `vendor/bin/kirby cloudflare-tools:license activate KEY`.
  Set Kirby's `url` option first, so the plugin knows the site's address.

Each public website uses one activation of your key. Local and staging sites
(`localhost`, `*.test`, `*.local`, `*.ddev.site`, private network addresses)
need none. Add other staging hosts to `licenseIgnore`:

```php
'ianhobbs.cloudflare-tools' => [
    'licenseIgnore' => ['staging.*', 'preview.example.com'],
],
```

**Moving a site to a new domain:** remove the licence on the old one first
(the same dialog, or `cloudflare-tools:license remove`), then activate it on
the new one. This frees the activation for reuse.

The plugin keeps what the licence server last said in
`site/config/.cloudflare-tools-license`, next to Kirby's own `.license`. A
key from the environment is never written to that file; a key entered in the
Panel is. Keep the file out of git and out of deploys, like `.license`:

```gitignore
/site/config/.cloudflare-tools-license
```

The plugin checks the key at most once a day, and only when someone opens
the Panel; visitors' requests never trigger a check. It sends only the key
and the website's address, and only to the licence server. If the server
can't be reached, the last answer stands for 14 days. A missing or inactive
licence never switches anything off.

```bash
vendor/bin/kirby cloudflare-tools:license            # status
vendor/bin/kirby cloudflare-tools:license check      # ask the licence server now
vendor/bin/kirby cloudflare-tools:license remove     # free this site's activation
```
