# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.1.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.1.0] - 2026-10-25

- Tells the client area's domain search whether a domain is free (action 0, as easy.gr's whois file for WHMCS asks)

## [1.0.9] - 2026-10-24

- Contact details of a domain (owner, administrative, technical), read and saved at easy.gr
- Private nameservers (ns1.the-domain with an IP address): register, change and delete

## [1.0.8] - 2026-10-24

- A phone number without a country code is taken as the client's country's (it was always Greek)

## [1.0.7] - 2026-10-23

- Off in a new install (it is for Greek sellers); an install that already has it keeps its switch. `GAIACP_PLUGINS_START_ENABLED` turns it on in a new install

## [1.0.6] - 2026-10-23

- Register and transfer check the client's address first and say which fields to fill in (easy.gr refuses a contact without a region, "sp1 - Συμπλήρωσε την περιοχή")

## [1.0.5] - 2026-10-22

- An HTTP error from easy.gr (e.g. 500 on register) now shows what easy.gr answered, not only the status code

## [1.0.4] - 2026-10-19

- A domain the easy.gr account doesn't hold (error 2303) now says so, e.g. on Sync after switching a domain to easy.gr

## [1.0.3] - 2026-10-19

- The EPP phone format moved to GaiaCP (shared with the Enom plugin); no change in behaviour

## [1.0.2] - 2026-10-17

- The password field of an easy.gr account no longer gets filled in by the browser's saved logins

## [1.0.1] - 2026-10-14

- New accounts no longer start with a list of TLDs: prices and the preferred registrar of each TLD are under Settings → Domain pricing

## [1.0.0] - 2026-10-10

- Domains at easy.gr: register, transfer, renew, nameservers, EPP code and the daily expiry sync
