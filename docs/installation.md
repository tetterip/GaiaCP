# Installing and running GaiaCP

## Requirements

- PHP **8.3+** (Laravel 13)
- Composer
- SQLite for development; MySQL/MariaDB in production

## Installation

From a release zip (GitHub → Releases → `gaiacp-<version>.zip`), on hosting with PHP 8.3+ and MySQL / MariaDB:

1. Create an empty database and a user for it.
2. Upload and unzip the zip, at the site's root or in a subfolder of it (e.g. `example.com/billing/`), and point the
   document root at its `public/` folder if your hosting lets you. If it doesn't, see
   [Document root](#document-root) below: on Apache and LiteSpeed it works as it is.
3. Open the site: every page goes to the installer (`/install`), which checks PHP and its extensions, asks for the
   database (writes `.env` with a new app key and creates the tables), then your business name and the first admin.
   It locks itself when it finishes (`storage/app/private/installed`, then `/install` is a 404) and shows the cron
   line to add. Run it right after uploading: until it finishes, anyone who opens the site can.
4. Log in at `/admin`: the setup guide at the top of the dashboard (also Settings → General → Setup guide) takes you
   through the rest, one step at a time: your business, region, mail, payment methods, tax, a server, domain prices,
   your first product, the client area and terms, backups, cron and two-factor sign-in. Skip what you don't need.
   The [admin guide](admin-guide/README.md) (also System → Help in the panel) explains each part.

### Document root

GaiaCP's own folder holds `.env` (the database password and the app key), `storage/` and the code; only `public/` is
meant to be served. In order of preference:

1. **Document root on `public/`** (the hosting panel's "Document root" setting): nothing else to do.
2. **Apache or LiteSpeed, document root on GaiaCP's folder** (most shared hosting, and subfolder installs such as
   `public_html/billing/`): the `.htaccess` that comes with GaiaCP sends every request into `public/` and answers
   404 for `.env`, `storage/`, `vendor/` and the rest. It needs `mod_rewrite` and `AllowOverride All` (the usual
   default); without `mod_rewrite` it refuses every request rather than show the files. Addresses never contain
   `/public`.
3. **nginx** ignores `.htaccess`. Point `root` at `public/`; for a subfolder, e.g. `/billing`:

```nginx
location ^~ /billing {
    alias /var/www/gaiacp/public;
    try_files $uri $uri/ @billing;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}

location @billing {
    rewrite ^ /billing/index.php last;
}
```

The installer fetches `/.env` and `/composer.json` through the site before it starts and stops if either can be read;
System health keeps checking it (Private files). In a subfolder, `APP_URL` includes it (`https://example.com/billing`):
the installer fills it in from the address it was opened at.

From SSH, `php artisan gaiacp:install` does the same, asking the questions in the terminal (or taking them as options,
e.g. `--db-connection=mysql --db-host=… --admin-email=…`, with `--no-interaction`).

### Updating

Settings → System → Updates shows this install's version, the latest release and its notes. **Update** backs up the
database first (as Settings → Backups says), downloads the release zip and checks its SHA-256 and the
signature of that checksum (Ed25519, by a key in `gaiacp.updates.keys`; see [Releases](releases.md#signing)), switches to maintenance
mode, swaps in the new code (`.env`, `storage/`, a SQLite database, plugins of your own and anything added to `public/`
stay), runs the migrations and comes back up. If a step after the swap fails, the old code is put back; the backup is
there for the database. From SSH: `php artisan gaiacp:update` (`--check` only looks).

Updates come from the GitHub releases of `GAIACP_UPDATE_REPOSITORY` (default `tetterip/GaiaCP`, public). A private
repository of your own needs `GAIACP_UPDATE_TOKEN` in `.env`, a token that can read it (fine-grained, Contents: read). Without a token GitHub allows
60 requests an hour per server address, shared with every site on it; on a shared server set `GAIACP_UPDATE_TOKEN` to
a fine-grained token with public repositories read-only (5000 an hour). A Git
checkout (deployed from Git) has no Update button: it is updated by deploying.

An install made before the installer existed (its own `.env`) counts as installed: the installer stays closed and
writes its lock file the first time the site is opened.

## Configuration

Day-to-day settings are edited in the admin under **Settings** (sidebar, or the user menu top right): a page of
cards grouped as General, Billing, Products & domains, Client area, Support, Email & notifications, Integrations,
Security, System and Logs, with a search box, and each settings page lists the others on its left. Values
are stored in the `settings` table, and each page saves only its own:

| Page | Settings |
|---|---|
| General | Days ahead that payment notices are created; the captcha on the login, sign up and forgot password forms (none, Google reCAPTCHA or Cloudflare Turnstile, each a plugin with its own keys page) and which forms it is on |
| Company | Name and email (the sender of reminder emails), phone, website, address, ΑΦΜ, Δ.Ο.Υ. (reminder signature); the logo (SVG, PNG or JPG; client area login page and menu, payment notices, top of every client email; the name without one; /admin keeps the GaiaCP brand), an optional dark mode logo and the client area's browser tab icon |
| Tax | Charge tax on or off, exclusive or inclusive prices, rules per country |
| VIES VAT check | Your EU country and VAT number (sent with each check so VIES gives a consultation number), Check a VAT number, and the reverse charge (off by default): no tax on the payment notices of EU businesses in another EU country whose VAT number VIES found valid within the days you set, with a note printed on the notice (built-in text in the client's language, or yours). The check mark next to the VAT number on the client form asks VIES and fills an empty company name |
| Client fields | Your own client fields: label per language, key, type (text, several lines, list of choices, yes / no, date), Required, In the client area (hidden, shown, editable) and Show on payment notices; drag to order |
| Client area | Maintenance mode (closes every client area page, login included, with your message or a redirect to another address; /admin, webhooks and admins signed in there are not affected), what clients can do themselves (domains: nameservers, EPP code, auto renew, contact details, private nameservers, registrar lock; hosting, orders, links in emails), whether new clients can sign up (off by default), the public order page at `/cart` (off by default; Settings → Public area adds a home page, domain search, domain prices, the knowledge base, announcements, a contact form and a server status page for visitors, each off by default) and the terms of service they accept on orders and sign up (a link and / or text, with a version; off by default) |
| Google sign-in | The Google OAuth client ID and secret (stored encrypted) behind "Continue with Google" on the client area's login and sign up pages, and the redirect URI to register at Google |
| ΑΑΔΕ | On/off switch (off by default: no lookup button), special access codes (ειδικοί κωδικοί, password stored encrypted) for the ΑΑΔΕ registry lookup: the magnifying glass next to the ΑΦΜ on the client form fills in the company name, Δ.Ο.Υ., profession and address. Create them at the ΑΑΔΕ special codes console (Δημιουργία Ειδικού Κωδικού for "Αναζήτηση Βασικών Στοιχείων Μητρώου Επιχειρήσεων"); Test lookup tries them |
| Products, Servers, Domain pricing, Registrars | The catalog, the cPanel/Plesk servers, the prices and preferred registrar of each TLD, and the registrar accounts (Email Notifications included) |
| Domains | Default nameservers for new registrations, auto renew for new domains, renew at the registrar on payment, free domains renew only with active hosting, IDN domains, grace and redemption fees, an SSL certificate offered with domains ordered on their own (with a discount on its first period) |
| Mail | SMTP server (host, port, STARTTLS / SSL / none, username, password stored encrypted; off = the `.env` mailer), header and footer text around every client email (Markdown, placeholders), and a Send test email button that tries the values before saving. The company logo (Settings → Company) is embedded in each email |
| Email templates | Every client email (signup, order confirmation, renewal and overdue notices, payment notices (proformas), hosting welcome, suspension, domain registration/renewal/transfer/expiry), each with an on/off switch, subject and text with placeholders such as `{$client_first_name}`, and a preview. Only the renewal, overdue and domain renewal notices are on until you switch others on |
| Backups | Daily or weekly at a set time, how many to keep, zip password (AES-256), email address, FTP/FTPS server and folder; Back up now, Download latest and Test FTP buttons |
| Automation | Days before / after the due date, time of the daily run, BCC address for every client email, auto-suspend after N days overdue (empty = never) |
| Admin users | Who can log in to the panel: add an admin (name, email, password with a Generate button, panel language), change their password, deactivate them (they can't log in, and an open session is refused on its next click), turn off their two-factor authentication when they lost the app. You can't deactivate or delete yourself; admins with entries in the activity log can only be deactivated. The list shows who has two-factor on and their last login. **Super admin** opens everything; any other admin opens only the areas ticked on their user (clients and orders, services, payment notices and payments, reports, activity log, business / products and domains / email settings), the rest is hidden from their menu and refused by address. Admin users, servers, registrars, plugins and the System settings are for super admins only. The first admin is a super admin, and nobody can change their own access |
| Login security | After 3 wrong passwords or two-factor codes from one IP address in 15 minutes (admin or client area login), that address can't open the login, sign up or forgot password pages for 15 minutes; both numbers can be changed, banning switched off, and addresses or ranges allowed that are never banned. **Banned IPs** next to it lists the bans, lifts one, or bans an address by hand |
| Logging | Module log on/off and how many days it is kept (30), how many days sent emails are kept (90). The Module log, Email log and Error log pages next to it show the entries; old ones are deleted every night |

Until a value is saved there, the default comes from `.env` (see `.env.example`) and `config/gaiacp.php`
(`APP_TIMEZONE`, `GAIACP_COUNTRY` and `GAIACP_DATE_FORMAT` for Settings → General → Region, `GAIACP_REMINDERS_AT`, `GAIACP_REMINDERS_BCC`, `GAIACP_PAYMENT_INSTRUCTIONS`, `GAIACP_SUSPEND_AFTER_DAYS`,
`MAIL_FROM_*`). These stay in `.env` only:

| Setting | Purpose |
|---|---|
| `MAIL_*` | The mailer used while Settings → Mail → SMTP is off |
| `GAIACP_CURRENCY` | The base currency (EUR); the installer asks it, other currencies go under Settings → Currencies |

## Production

The installer creates the first admin (on an install set up by hand, `php artisan make:filament-user`); add the others
under Settings → Admin users.

The scheduler must run every minute:

```
* * * * * cd /path/to/gaiacp && php artisan schedule:run >> /dev/null 2>&1
```

**System health** (Settings → System health) shows whether cron runs (and with which PHP), when each daily job last ran, and
any setup problem; `php artisan gaiacp:health` prints the same and fails when something is wrong.

## Public demo

`GAIACP_DEMO=true` turns an install into a public demo: made-up data put back every few minutes
(`gaiacp:demo-reset`), the demo logins filled in, and nothing leaving the server (no email, no panel, registrar or
other outgoing calls, no plugin uploads or updates). Never on a real install: see [demo-site.md](demo-site.md).
