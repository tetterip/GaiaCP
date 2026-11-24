# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.7.0] - 2026-11-24

- CPU on the dashboard: the load average and processor cores from the server statistics (`server get stat`, `ReportsLoad`), read every 5 minutes (not yet tried on a real server)

## [1.6.3] - 2026-11-17

- Security: Log in to Plesk, its shortcuts and changing the Plesk login's password work only when the Plesk customer that owns the subscription is the client's own (made by GaiaCP for them, or made by hand and holding no other client's subscription). A subscription moved to another client stays under the old client's Plesk customer, so its new client no longer gets that login

## [1.6.2] - 2026-11-16

- Change password on a subscription whose customer has another login (made by the client's first service, or by hand) names that login and can change its password too (`SharesPanelLogin`), in the admin and the client area

## [1.6.1] - 2026-11-12

- Usage: this month's bandwidth adds the traffic of the subscription's other domains (`site get`), which Plesk counts apart from its main domain, as WHMCS's Plesk module does
- Change password on the service that made the client's Plesk login changes that login's password too, not only the subscription's FTP / SSH user

## [1.6.0] - 2026-10-27

- SSL certificates on the client's own hosting: the validation CNAME goes into the subscription's DNS zone (`dns add_rec`; Plesk's XML API can't write files) and the issued certificate is installed in the subscription and used for its hosting (`certificate install`, then `certificate_name`) (`InstallsCertificates`; not yet tried on a real server)

## [1.5.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.5.0] - 2026-10-23

- Lists every hosting account on the server (`ListsAccounts`), for Servers → Compare with the server

## [1.4.0] - 2026-10-19

- Quick shortcuts in the client area: Mail, Applications, File Manager, Databases, Statistics and Users, each opened already signed in (not yet tried on a real server)

## [1.3.0] - 2026-10-17

- Servers → Log in to control panel: a one-time Plesk session for the server's own login (`create_session`; `admin` with a secret key)

## [1.2.0] - 2026-10-11

- Usage: disk space and this month's traffic against the subscription's limits, plus mailboxes, databases, sites and subdomains against their limits and the databases' size, from `webspace get` with the `gen_info`, `limits`, `stat` and `disk_usage` datasets

## [1.1.0] - 2026-10-11

- Client area login: a one-time Plesk session for the subscription's customer (`server create_session`, opened through `rsession_init.php`), behind "Log in to Plesk"; none for subscriptions the admin or a reseller owns

## [1.0.0] - 2026-10-10

- Subscriptions on Plesk over the XML API, one Plesk customer per client: create, suspend, unsuspend, terminate, change service plan and password, account state
