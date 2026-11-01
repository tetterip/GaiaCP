# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.1.0] - 2026-11-01

- Admins can log in to /admin with Google too, when ticked in its settings (off by default): only an existing active
  admin with the same email address, with its own redirect URI to add in Google Cloud Console
- A login with two-factor authentication on now gives its code on the login page after Google, instead of being refused
- "Last used" on the button when this browser last logged in with Google

## [1.0.3] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.2] - 2026-10-24

- Its settings page is under Settings → Client area (Settings regrouped)

## [1.0.1] - 2026-10-12

- An On switch at the top of Settings → Google sign-in turns the plugin on or off (the same as Settings → Plugins), and the
  page stays open while it is off

## [1.0.0] - 2026-10-11

- "Continue with Google" on the client area's login and sign up pages: logs in the client whose email address Google
  confirms, gives an existing client without a login one, and sends a new address to the sign up form (when signing
  up is on). Settings → Google sign-in for the OAuth client ID and secret
