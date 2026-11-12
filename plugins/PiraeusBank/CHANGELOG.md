# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-11-12

- Card and IRIS payments through Piraeus Bank (epay paycenter): a "Pay by card (Piraeus Bank)" button on unpaid payment
  notices in the client area asks the bank for a transaction ticket and opens the bank's own page. The payment is
  recorded only when the bank's answer carries a HashKey signed with that payment's ticket, exactly once; an IRIS
  transfer the bank has not confirmed yet waits for an admin. Answers that never come are closed after two hours.
  Settings → Piraeus Bank for the account details and the addresses to give the bank. Ported from the WooCommerce
  plugin "Piraeus Bank WooCommerce Payment Gateway" by Papaki (Enartia S.A.), based on emspace.gr's, GPL-3.0-or-later
