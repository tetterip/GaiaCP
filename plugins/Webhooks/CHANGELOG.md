# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.5] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.4] - 2026-10-25

- Greek file keeps only its own texts (Edit and View come from GaiaCP)

## [1.0.3] - 2026-10-24

- Its settings page is under Settings → Email & notifications (Settings regrouped)

## [1.0.2] - 2026-10-24

- Dates follow Settings → General → Date format

## [1.0.1] - 2026-10-23

- URLs on loopback, private, link-local (cloud metadata) or reserved addresses are refused when saved and again
  when sent, and a delivery connects to the address that was checked. `GAIACP_WEBHOOKS_ALLOW_PRIVATE=true` allows
  them, e.g. for n8n on the same server.

## [1.0.0] - 2026-10-23

- Webhooks: GaiaCP events (orders, payment notices, payments, services, domains, tickets, servers) sent as signed JSON
  POSTs to the URLs set under Settings → Webhooks, each with the events it wants. Sent by the scheduler every minute,
  retried after 1, 5 and 30 minutes, 2 and 12 hours; delivery history with Resend, Test button, a bell when an
  endpoint keeps failing and switched off after 50 failures in a row.
