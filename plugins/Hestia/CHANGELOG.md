# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

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
