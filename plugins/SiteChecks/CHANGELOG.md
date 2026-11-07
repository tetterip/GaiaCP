# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.7] - 2026-11-07

- The check's result reads as proper singular or plural in every language, instead of "site(s)"

## [1.0.6] - 2026-11-06

- Its times are stored in UTC like GaiaCP's (StoresTimesInUtc); the GaiaCP update converts the ones already kept

## [1.0.5] - 2026-11-06

- The daily site check is no longer skipped for a whole day after a run that was cut short (a server restart, for example)

## [1.0.4] - 2026-11-02

- The HTTPS check connects only to a public address it has just looked up (or the hosting server's own), never to a
  private or reserved one a client's DNS points to, and to that address rather than a second lookup

## [1.0.3] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.2] - 2026-10-24

- Its settings page is under Settings → Integrations (Settings regrouped)

## [1.0.1] - 2026-10-24

- Dates follow Settings → General → Date format

## [1.0.0] - 2026-10-23

- Checks every active hosting account's domain once a day: whether it still points to its server (DNS) and whether its
  HTTPS certificate is valid, trusted and not about to expire. Services → Site checks lists them, with Check now; a
  new problem goes to the bell of the admins who open services, once
