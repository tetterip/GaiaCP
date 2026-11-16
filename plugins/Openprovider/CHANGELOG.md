# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.1] - 2026-11-16

- Sandbox mode uses Openprovider's current sandbox address, `https://api.sandbox.openprovider.nl` (the old one on port 8480 no longer answers)
- Calls the API's current version path `/v1`, as Openprovider's developer portal documents it (`/v1beta` answers the same)
- The username field says to turn on API access for the contact person first

## [1.0.0] - 2026-11-12

- Domains at Openprovider: register, transfer, renew, nameservers, the daily expiry sync and the EPP code
- Contact details (registrant, admin, tech, billing), private nameservers and the registrar lock
- Tells the client area's domain search whether a domain is free, and its TLD prices (1 year, in the account's currency) for Settings → Domain pricing → Import from registrar
- Sandbox mode, for Openprovider's sandbox
