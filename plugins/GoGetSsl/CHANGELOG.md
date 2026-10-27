# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.2] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.1] - 2026-10-25

- No more Sandbox switch: GoGetSSL's old test system (sandbox.gogetssl.com) no longer exists. Test connection is
  free; to try an order, buy the cheapest certificate and cancel it within GoGetSSL's refund days

## [1.0.0] - 2026-10-24

- Sells SSL certificates through a GoGetSSL reseller account: an SSL product picks one of its certificates, and paying
  for the service orders it. GaiaCP makes the key and request, the client validates the domain (DNS record, file or
  email) and downloads the certificate from the client area. Check status, change validation, reissue, renew and
  cancel from the service's SSL menu. Settings → GoGetSSL for the API login, sandbox and the default validation, with
  Test connection
