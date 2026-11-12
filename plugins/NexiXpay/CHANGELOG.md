# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-11-12

- Card payments through Nexi XPay (the Nexi Payment Gateway API, for Alpha Bank merchants since mid-2022 and other
  Nexi acquiring customers): "Pay by card (Nexi)" on unpaid payment notices in the client area opens Nexi's own page.
  GaiaCP records the payment only after reading the order back from Nexi's API with the merchant's API key, and only
  if Nexi took the amount asked for, once. Every hour it asks Nexi about payments still waiting. Settings → Nexi XPay
  for the test and live API keys. The request and the order states follow Nexi's WooCommerce plugin (Nexi XPay 8.4.0,
  GPL-3.0)
