# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.5] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.4] - 2026-10-24

- Its settings page is under Settings → Billing (Settings regrouped)

## [1.0.3] - 2026-10-19

- A card payment whose recording stopped part way (the request was cut off) no longer stays "processing" unseen: after
  an hour it shows as an online payment to match by hand, in the bell and the daily summary

## [1.0.2] - 2026-10-16

- A payment notice in a currency Stripe takes in whole units (JPY and the like) does not offer Pay by card, since
  amounts here are in hundredths

## [1.0.1] - 2026-10-12

- Settings → Stripe now sits under Settings → Payment methods, in its list and in the menu, in place of its own card
- An On switch at the top of Settings → Stripe turns the plugin on or off (the same as Settings → Plugins), and the
  page stays open while it is off

## [1.0.0] - 2026-10-11

- Card payments with Stripe Checkout: a "Pay by card" button on unpaid payment notices in the client area opens
  Stripe's own payment page (no card details reach GaiaCP). The payment is recorded on the notice once Stripe confirms
  it, through its signed webhook or the client's return from Stripe, exactly once, renewing and unsuspending as a
  payment recorded by hand does. Settings → Stripe for the test and live keys, the webhook signing secrets and the
  mode; a payment that can't be recorded (the notice changed meanwhile) is listed in the admins' daily summary
