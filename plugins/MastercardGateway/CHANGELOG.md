# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-11-12

- Card payments through Mastercard Gateway (Mastercard Payment Gateway Services, the hosted checkout many banks offer
  their merchants): "Pay by card" on unpaid payment notices in the client area opens the gateway's own payment page.
  GaiaCP records the payment only after reading the order back from the gateway's API with the merchant's API
  password, and only if it captured the amount asked for, once. Every hour it asks the gateway about payments still
  waiting. Settings → Mastercard Gateway for the gateway address and the test and live merchant details. The requests
  follow Fingent's WooCommerce plugin (Mastercard Gateway 1.5.4.1, Apache-2.0)
