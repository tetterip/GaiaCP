# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.5.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.5.0] - 2026-10-23

- Lists every hosting account on the server (`ListsAccounts`), for Servers → Compare with the server

## [1.4.0] - 2026-10-19

- Quick shortcuts in the client area: Email accounts, File Manager, MySQL databases, phpMyAdmin, Statistics and Backups, each opened already signed in (not yet tried on a real server)

## [1.3.0] - 2026-10-17

- Servers → Log in to control panel: a one-time WHM session for the server's own user (`create_user_session` for `whostmgrd`)

## [1.2.0] - 2026-10-11

- Usage: disk space and this month's bandwidth against the account's limits, from WHM `accountsummary` (disk) and `showbw` (this month's bandwidth), plus email accounts, databases and their space, addon / parked domains and subdomains from the account's StatsBar (UAPI through `uapi_cpanel`)

## [1.1.0] - 2026-10-11

- Client area login: a one-time cPanel session for the account (WHM `create_user_session`), behind "Log in to cPanel"

## [1.0.0] - 2026-10-10

- Hosting accounts on cPanel / WHM over WHM API 1 with an API token: create, suspend, unsuspend, terminate, change package and password, account state
