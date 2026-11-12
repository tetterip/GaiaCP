# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.3] - 2026-11-12

- Every hour it asks PayPal about the orders still waiting: an approved one whose client never came back is taken (if the notice still asks for that amount), and a payment that was clearing is recorded once completed, even when the webhook never came

## [1.0.2] - 2026-11-06

- Its times are stored in UTC like GaiaCP's (StoresTimesInUtc); the GaiaCP update converts the ones already kept

## [1.0.1] - 2026-11-06

- The hourly check for stuck PayPal orders is no longer skipped for a whole day after a run that was cut short (a server restart, for example)

## [1.0.0] - 2026-10-31

- Payments with PayPal: a "Pay with PayPal" button on unpaid payment notices in the client area opens PayPal's own
  page. GaiaCP takes the money only after checking that the notice still asks for that amount, and records the payment
  once PayPal confirms it (on the client's return, or through the webhook when the client closes the page or the payment
  clears later), exactly once. Settings → PayPal for the sandbox and live app keys and the mode
