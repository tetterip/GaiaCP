# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.4] - 2026-11-07

- Counts read as proper singular or plural ("1 monitor", "3 monitors") in every language, instead of "monitor(s)"

## [1.0.3] - 2026-11-07

- Reads the pages as HetrixTools sends them (meta.pagination last / next), so exactly 100 monitors no longer asks for an empty second page; checked against the real API

## [1.0.2] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.1] - 2026-10-24

- Its settings page is under Settings → Integrations (Settings regrouped)

## [1.0.0] - 2026-10-24

- HetrixTools as a monitoring service: a server links to one of its uptime monitors and shows Up / Down on the Servers
  list and the dashboard, with the monitor's uptime; going down and coming back up notify super admins in the bell.
  Settings → HetrixTools for the API token (Free plan included), with Test connection
