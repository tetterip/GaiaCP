# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.1.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.1.0] - 2026-10-23

- Lists every hosting account on the server (`ListsAccounts`), for Servers → Compare with the server

## [1.0.0] - 2026-10-19

- Hosting accounts on DirectAdmin over its legacy API (`CMD_API_*`, port 2222), as the admin or a reseller with a login key or password: create (`ACCOUNT_USER`), suspend, unsuspend and delete (`SELECT_USERS`), change package (`MODIFY_USER`) and password (`USER_PASSWD`), account state, packages (`PACKAGES_USER`)
- New accounts go on the server's "IP for new accounts", else the login's first IP (`SHOW_RESELLER_IPS`); generated usernames stay within DirectAdmin's 10 characters
- Usage: disk space and bandwidth against the package's limits, plus mail accounts, databases, domains, parked domains and subdomains (`SHOW_USER_USAGE` / `SHOW_USER_CONFIG`)
- Client area and Servers login open the DirectAdmin login page
