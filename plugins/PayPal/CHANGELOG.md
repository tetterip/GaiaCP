# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-10-31

- Payments with PayPal: a "Pay with PayPal" button on unpaid payment notices in the client area opens PayPal's own
  page. GaiaCP takes the money only after checking that the notice still asks for that amount, and records the payment
  once PayPal confirms it (on the client's return, or through the webhook when the client closes the page or the payment
  clears later), exactly once. Settings → PayPal for the sandbox and live app keys and the mode
