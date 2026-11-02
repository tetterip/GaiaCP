# Admins and security

## Admin users

Settings → Security → **Admin users** (super admins only) lists who can log in to the panel, with their last login and
whether two-factor is on. For each admin you set the name, email, password (with a Generate button), panel language,
**Receive system emails** and the bell.

**Super admin** opens everything. Any other admin opens only the **areas** ticked on their user; the rest is hidden from
their menu and refused by address:

| Area | Opens |
|---|---|
| Clients and orders | Clients, their details and orders |
| Hosting, domains and other services | Services and their Account and Registrar actions |
| Payment notices and payments | Payment notices and recorded payments |
| Support tickets | Tickets, departments, canned replies, the knowledge base |
| Sales: leads and quotes | Leads and quotes, for a salesperson who shouldn't see payments or servers |
| Reports | Income and service reports |
| Activity log | Everything done in the panel and by the daily run |
| Settings: business | Company, billing, tax |
| Settings: products and domains | Products and domain settings |
| Settings: email | Mail server, layout and email templates |

Admin users, servers, registrars, plugins and the System settings are for super admins only. The first admin is a
super admin, and nobody can change their own access. The dashboard and Help are open to every admin.

Turn off **Can log in** for an admin who leaves: they can't log in, and an open session is refused on its next click.
Admins with entries in the activity log can only be deactivated, never deleted, so the log keeps saying who did what.
You can't deactivate or delete yourself.

## Two-factor authentication

Each admin turns it on in their Profile: after the password, a code from an authenticator app, with recovery codes for
when the phone is lost. Until it is on, a reminder at the top of the panel suggests it. A super admin can turn it off
for an admin who lost both the app and the codes (Admin users), or from SSH:
`php artisan gaiacp:disable-2fa admin@example.com`.

**Forgot password?** on the login page emails active admins a reset link; two-factor is still asked afterwards.

## Other ways to log in

Settings → Security → **Login security** → Other ways to log in:

- **Passkeys** (on by default): each admin adds one from their Profile → Passkeys on each phone or computer, then
  presses **Log in with a passkey** and unlocks the device with a fingerprint, face or screen lock. No password and no
  two-factor code: the passkey already needs the device and its unlock. Remove a lost device's passkey from the same
  list. Passkeys belong to the site's address, so they stop working if GaiaCP moves to another domain.
  Adding one asks for the password, unless the login signed in within the last 10 minutes, and emails the login, so a
  browser left signed in can't quietly get a way back in. A password reset (Forgot password?) removes every passkey of
  that login. **Remove passkeys** on an admin user (Settings → Security → Admin users) or on a client's Sign-ins tab removes them
  for a lost device or a login someone else may have used.
- **Sign-in link by email**: "Email me a sign-in link" on the login page sends a link that works once, for 15 minutes.
  It is on for the client area and off for admins until you tick it. Opening the link logs nobody in by itself: the
  login page then asks to press **Log in** (and for the two-factor code when it is on), so mail scanners that open
  links can't use it.
- **Google**: tick "Admins can log in to /admin with Google too" in Settings → Google sign-in, and add the second
  redirect URI it shows in Google Cloud Console. Only an active admin whose email address is the Google account's gets
  in, never someone new; two-factor is asked afterwards when it is on.

The login page marks the way this browser logged in last with **Last used**.

## Login security

Settings → Security → **Login security**: after 3 wrong passwords or two-factor codes from one IP address in 15 minutes
(on the admin or the client area login), that address can't open the login, sign up or forgot password pages for 15
minutes. Change both numbers, switch banning off, or list addresses and ranges that are never banned, such as your
office. **Banned IPs** lists the bans, lifts one, or bans an address by hand. Wrong REST API keys count as wrong
passwords, and a banned address gets no API answers either.

## Captcha

Settings → General → **Captcha** puts a "not a robot" check on the login, sign up, forgot password, domain search and
contact forms you pick. Each captcha is a plugin: **Google reCAPTCHA** or **Cloudflare Turnstile**. Enter its keys on its
own settings page first, then pick it here.

## Behind Cloudflare or a proxy

GaiaCP trusts Cloudflare's addresses by default, so bans and logs see the visitor's real IP. System health warns when
Cloudflare is in front but not trusted, or set to Flexible SSL.

## What GaiaCP keeps secret

- Server credentials, registrar logins, API keys and secret settings are stored encrypted. Secret fields are never
  sent back to your browser: a blank field keeps the stored value.
- Service passwords are shown to admins on the service form when you reveal them, and never in lists, logs or exports.
- The module log masks passwords, tokens and EPP codes.

## The activity log

System → **Activity log** records everything that changes something: who (an admin, the daily run, a client, an API
key), what, and when. Use it to find out why a service is suspended, who recorded a payment or what the daily run did.
Each service and client has its own Activity tab.

Next: [Plugins and integrations](13-plugins-and-integrations.md).
