# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.10] - 2026-10-30

- Domains land on the registrar account of their WHMCS registrar module (easy.gr, Enom or any registrar plugin of the
  same module), not only easy.gr; a domain whose registrar has no account under Settings → Registrars is imported as
  tracked only

## [1.0.9] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.8] - 2026-10-25

- A product's WHMCS group becomes its product group (Settings → Product groups), matched by name or created

## [1.0.7] - 2026-10-25

- A repeating WHMCS service is only matched to a repeating billing cycle (GaiaCP now also has One time)

## [1.0.6] - 2026-10-24

- Its settings page is under Settings → System (Settings regrouped)

## [1.0.5] - 2026-10-24

- Every other client custom field is imported as one of Settings → Client fields (type, choices, required, admin only,
  shown on invoices), with each client's value

## [1.0.4] - 2026-10-24

- Dates in its report follow Settings → General → Date format; a client without a country gets Settings → General → Country

## [1.0.3] - 2026-10-19

- DirectAdmin servers are imported too (with the DirectAdmin plugin), with their username and decrypted password; a
  server type with no plugin in GaiaCP is still skipped with a note

## [1.0.2] - 2026-10-17

- The password, encryption hash and API secret fields no longer get filled in by the browser's saved logins

## [1.0.1] - 2026-10-16

- A client who pays in another currency in WHMCS keeps that currency (Settings → Currencies); a currency not set up
  here yet is added with WHMCS's prefix, suffix and rate. Products take their prices in the base currency

## [1.0.0] - 2026-10-10

- Import from a WHMCS database: servers, products and prices, clients, hosting, domains, billable items and unpaid invoices, with a dry run and re-runs that skip what is already here
- Optional WHMCS API for the passwords the database key can't decrypt
