# Changelog

## 1.0.0 — unreleased

First release.

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
