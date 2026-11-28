# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.5.0] - 2026-11-28

- Account → Free SSL (Let's Encrypt) (`IssuesFreeCertificates`): `v-add-letsencrypt-domain` for the account's web domain with its aliases, then for its mail domain when it has one; a certificate it already has is renewed. Not yet tried on a real server

## [1.4.1] - 2026-11-26

- The CPU bar reads the load average of the last 5 minutes (the second number of LOADAVERAGE) instead of the last minute, so a short burst at the moment of reading no longer shows as a busy hour.

## [1.4.0] - 2026-11-26

- Package sizes: the package pickers show each package's disk quota and bandwidth from `v-list-user-packages` (`DescribesPackages`)

## [1.3.0] - 2026-11-24

- CPU on the dashboard: the load average from `v-list-sys-info` (`ReportsLoad`), read every 5 minutes; the processor cores come from the server form (not yet tried on a real server)

## [1.2.2] - 2026-11-07

- Compare with the server no longer lists the panel's admin login as an unbilled account (a real HestiaCP 1.10 puts it on the "default" package, with ROLE admin)
- The module log masks each user's password reset key (RKEY) from v-list-users

## [1.2.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.2.0] - 2026-10-23

- Lists every hosting account on the server (`ListsAccounts`), for Servers → Compare with the server

## [1.1.0] - 2026-10-17

- Servers → Log in to control panel: HestiaCP's login page (it has no one-time login)

## [1.0.0] - 2026-10-12

- Hosting accounts on HestiaCP over its API (`/api/`), with an access key or the admin password: create (`v-add-user` + `v-add-domain`), suspend, unsuspend, terminate, change package and password, account state, packages
- Usage: disk space and bandwidth against the package's limits, plus mail accounts, databases and their space and web domains, from `v-list-user`
- Client area login opens the HestiaCP login page: HestiaCP has no one-time login link, so the client signs in with their own password
