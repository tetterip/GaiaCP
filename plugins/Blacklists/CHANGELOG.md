# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.5] - 2026-11-06

- Its times are stored in UTC like GaiaCP's (StoresTimesInUtc); the GaiaCP update converts the ones already kept

## [1.0.4] - 2026-11-06

- The hourly blacklist check is no longer skipped for a whole day after a run that was cut short (a server restart, for example)

## [1.0.3] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.2] - 2026-10-24

- Its settings page is under Settings → Integrations (Settings regrouped)

## [1.0.1] - 2026-10-24

- Dates follow Settings → General → Date format

## [1.0.0] - 2026-10-18

- Checks every active server's IP address (or its hostname's) against DNS blacklists (Spamhaus ZEN, SpamCop,
  Barracuda, PSBL by default) every hour, and tells super admins in the bell when one gets listed and when it is
  removed again. Settings → IP blacklist check for the lists, Check now, and what is listed right now
