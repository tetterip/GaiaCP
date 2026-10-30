# Public demo site

A GaiaCP anyone can try: made-up data, the demo logins filled in on both login pages, and the data put back every
few minutes. It is a normal Git checkout of GaiaCP with `GAIACP_DEMO=true` (`App\Support\Demo`).

## What the demo does

- **Data**: `Database\Seeders\DemoSeeder` (a small hosting company, about 25 clients with hosting, domains and care
  plans, a year of payments, payment notices, an order, tickets, leads, quotes, knowledge base, announcements and 30
  days of uptime; product colours and tags, one tag hidden). Dates are relative to today.
- **Reset**: `php artisan gaiacp:demo-reset`, from the scheduler every `GAIACP_DEMO_RESET_MINUTES` (default 10,
  counted from the hour). The data is built once a day (and after a deploy that adds migrations) into
  `storage/app/private/demo/demo.sqlite` in a process of its own; each reset copies its tables into the live database
  in one transaction (well under a second), so visitors never see half of it. Sessions are kept: whoever is signed in
  stays signed in, but a cart filled before the reset is emptied (`storage/app/private/demo/restored` says when it
  ran). Uploads made meanwhile are deleted. `--rebuild` builds the data again first.
- **Logins**: admin `admin@example.com` / `demo` (a super admin), client `client@example.com` / `demo`. They are
  filled in on the login pages; their email, password and two-factor authentication can't be changed and they can't
  be deleted.
- **Bar**: every page shows that it is a demo and a countdown to the next reset; the page reloads after it.
- **Nothing leaves the server**: email only goes to the log, every HTTP call fails as "cannot connect", plugin
  uploads, uninstalls and updates are off, and the pages below are closed.
- **Closed pages** (`Demo::CLOSED`): Updates, Error log, System health, Backups, API keys, Mail, Cookies (its
  scripts would run for every visitor, so none are added), Login security, Languages, IP bans. **Plugins kept off**
  (`Demo::PLUGINS_OFF`, their pages closed): Email to ticket, WHMCS import, Stripe, Site checks, IP blacklist check.
  Logo uploads and ticket attachments are off.
- **Servers, registrar, monitoring**: the Demo plugin (`plugins/Demo`, loaded only in the demo): the panel answers
  from the services GaiaCP stores (create, suspend, terminate, change password always work), the registrar from the
  domains (register, renew, transfer always work; famous names show as taken in the domain search), and every server
  is up.
- No search engine indexes it (noindex on every page).

## Setting it up on Plesk (no SSH)

1. **Subdomain**: Websites & Domains → Add Subdomain `demo.gaiacp.digital`, PHP 8.3 or later. Let's Encrypt
   certificate on it (SSL/TLS Certificates).
2. **Code**: Git on the subdomain, the GaiaCP repository, branch `main`, deployed into the subdomain's folder (e.g.
   `demo.gaiacp.digital/`), Automatic deployment. Document root: the folder's `public/` (or the folder itself, the
   root `.htaccess` sends requests to `public/`).
3. **`.env`** in that folder (File Manager), from `.env.example`, with:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://demo.gaiacp.digital
   APP_KEY=            # filled by key:generate below
   DB_CONNECTION=sqlite
   GAIACP_DEMO=true
   GAIACP_DEMO_RESET_MINUTES=10
   MAIL_MAILER=log
   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=sync
   GAIACP_PLUGIN_UPLOADS=false
   ```

   Create the empty file `database/database.sqlite`.
4. **Deploy actions** (Git → Additional deployment actions), one command per line, with the subscription's PHP (e.g.
   `/opt/plesk/php/8.4/bin/php`). They run in the system user's shell: set the subscription's "Access to the server
   over SSH" (Hosting settings) to `/bin/bash`, not the chrooted one, which can't see `/opt/plesk/php`. Composer is
   Plesk's own (the PHP Composer extension):

   ```
   /opt/plesk/php/8.4/bin/php /usr/local/psa/var/modules/composer/composer.phar install --no-dev --optimize-autoloader --no-interaction
   /opt/plesk/php/8.4/bin/php artisan key:generate --force   # first deploy only, then remove this line
   /opt/plesk/php/8.4/bin/php artisan migrate --force
   /opt/plesk/php/8.4/bin/php artisan gaiacp:demo-reset --rebuild
   /opt/plesk/php/8.4/bin/php artisan optimize
   /opt/plesk/php/8.4/bin/php artisan filament:optimize
   ```

   Then Deploy now (the `.env` and the empty database file must exist first).
5. **Cron**: Scheduled Tasks → Add Task, **Run a PHP script** (works whatever the shell; a command needs the
   non-chrooted one): script path `/var/www/vhosts/gaiacp.digital/demo.gaiacp.digital/artisan`,
   arguments `schedule:run`, PHP 8.4, cron style `* * * * *`. Run now answers "No scheduled commands are ready to run"
   or runs `gaiacp:demo-reset`.
6. Open https://demo.gaiacp.digital/admin: the bar shows the countdown and the login is filled in.

The installer (`/install`) is not used: the demo seeds its own admin. To reset by hand, run
`artisan` with the arguments `gaiacp:demo-reset` as a Scheduled Task (Run a PHP script) with "Run now".
