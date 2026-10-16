# Changelog

## 1.1.0 — unreleased

- **Kirby 4 support, as a free lite edition.** On Kirby 4.3 and later the
  Cloudflare area has one page: the Development Mode switch, Purge
  everything and the traffic charts. Purge-on-save, the CLI commands and
  `site()->cloudflare()` work as on Kirby 5. Kirby 4 sites need no licence:
  there is no licence status or dialog, and `cloudflare-tools:license` says
  so. Only the API routes the Overview page uses are registered.
- New `developmentMode` role permission, **on by default**: editors can now
  switch Development Mode from the Overview tab without the `settings`
  permission. Set it to `false` in a role's blueprint to keep the old
  behaviour. The Caching tab's toggle still needs `settings`.
- New Panel API route `POST /api/cloudflare-tools/development-mode`
  (`{"enabled": true}`), which the Overview switch now uses.
- PHP 8.1 or later (was 8.3).
- Kirby 6 is not supported by 1.x; it will be plugin 2.0. Composer now
  refuses Kirby before 4.3 and Kirby 6. Without Composer (ZIP or submodule),
  the plugin switches itself off on those versions and logs why, instead of
  breaking the site.
- The tab bar is hidden when the area has only one tab.
- Tests run in CI on Kirby 4.3, 4.9 and 5, PHP 8.1 to 8.5.

## 1.0.1 — 2026-10-15

- Licence checks are switched on: keys are activated and checked against
  the Cloudflare Tools store on Polar. A key saved while checks were off is
  activated the next time someone opens the Cloudflare area. As before, a
  missing or inactive licence never switches anything off.
- The licence dialog and Panel › System link to the shop at
  https://codey.net.au/apps/cloudflare-tools, as does the README.

## 1.0.0 — 2026-10-12

First stable release. The licence agreement is final.

- **Breaking:** the CLI commands are renamed from `cloudflare:*` to
  `cloudflare-tools:*` (`check`, `purge`, `api`, `license`), matching the
  plugin's other names. Update Janitor buttons in your blueprints, e.g.
  `command: 'cloudflare-tools:purge'`.
- `LICENSE`: the draft notice is removed.
- README: the install command no longer needs `@beta`; Git submodule
  install added; PHP 8.3 stated as the minimum version.

## 1.0.0-beta.1 — 2026-10-05

First pre-release. The licence agreement is still in draft, and licence
checks stay off until the Polar store exists.

- Licensed under the Cloudflare Tools Licence Agreement: free on development
  and staging sites, one paid activation per public website. See `LICENSE`.
- Licence status in Panel › System, with an activation dialog; keys from the
  Panel, `CLOUDFLARE_TOOLS_LICENSE` or `cloudflare:license`; checked against
  Polar at most daily, only from the Panel; never switches features off.
- Cloudflare area in the Panel: Overview, Caching, Security, SSL/TLS, Speed &
  Network, DNS and Diagnostics tabs.
- Purge everything, or by URL, prefix, hostname or cache tag.
- Page "Purge" view button, and optional purge-on-save (`autoPurge`).
- Zone settings from a catalogue of Free-plan settings, validated before
  they are sent.
- I'm Under Attack with restore, Bot Fight Mode, Block AI bots, IP Access Rules.
- Edge certificate status; DNS records with the proxy switch, writes opt-in.
- Traffic analytics (requests, page views, visitors, bandwidth, cache hit
  rate, error rate, threats, countries).
- Role permissions: `analytics`, `purge`, `settings`, `security`, `dns`.
- CLI commands `cloudflare:check`, `cloudflare:purge`, `cloudflare:api`, also
  usable as Janitor buttons.
- PHP API: `site()->cloudflare()`, `forDomain()`, `forZone()`, `request()`,
  and custom API gates through `Cloudflare::extend()` or the `endpoints` option.
- Credentials read from the environment, so a `.env` file kept outside the
  web root needs no config.
