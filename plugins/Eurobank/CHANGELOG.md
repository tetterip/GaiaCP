# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-11-12

- Card payments through Eurobank (Cardlink): a "Pay by card (Eurobank)" button on unpaid payment
  notices in the client area opens the bank's own page. The payment is recorded only when the bank's answer carries a
  valid digest (signed with the shared secret) and names the amount GaiaCP asked for, exactly once. Answers that never
  come are closed after two hours. Settings → Eurobank for the merchant ID, shared secret and test or live mode.
  Ported from the WooCommerce plugin "Eurobank WooCommerce Payment Gateway" by Papaki (Enartia S.A.), GPL-3.0-or-later
