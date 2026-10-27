# GaiaCP

Hosting billing and provisioning panel, a simplified WHMCS for a single operator or a small team.
Scope, out-of-scope list and build order are in `docs/features.md` (`README.md` has the short version); keep both
honest when features are added or dropped.

## Working in a session

- Cloud sessions are set up by `.claude/hooks/session-start.sh`: `composer install`, `.env` + key, SQLite
  `database/database.sqlite` migrated (seeded with demo data when new). Don't repeat these steps; `php artisan serve`
  then log in at `/admin` as admin@example.com / password. No npm step: nothing uses Vite
- Tests: run the ones for what you changed (`php artisan test --filter=<TestName>`), plus `LocalesTest` after adding
  texts; the full suite (about 90 s) once before pushing, with `./vendor/bin/pint`
- Screenshots (before / after, light, dark, phone) only for bigger features or when asked, not for small UI tweaks
- Ask the maintainer before merging to `main`: the owner's staging deploys every push to `main`, even when CI fails
- Check `git remote -v` before pushing after a `composer install` (a failed source clone once rewrote `origin`)

## Stack

- Laravel 13, PHP **8.3+**, Filament 5; SQLite in development and tests, MySQL/MariaDB in production
- Admin panel at `/admin`, client area = a second Filament panel at the site root (`app/Filament/Client/`)
- Integrations (server panels, registrars, payments, captcha, sign-in, monitoring, themes) are plugins in `plugins/<Name>/`
- Timezone, date format and the country of new clients: Settings → General → Region (`App\Support\Region`, asked by the
  installer; new installs start in UTC, existing ones were pinned to Europe/Athens, `d/m/Y`, GR); languages: `en` and `el` ship, plus machine translations (`Translations::MACHINE_TRANSLATED`: de, es, fr, it, nl, pl, pt_BR, tr; give their `lang/<code>.json` new texts too), others are uploaded under Settings → Languages (kept in `storage/app/private/lang`)

## Project rules

- A new server panel, registrar, payment method or integration is a plugin; bundled plugins are never uninstallable,
  and disabling one keeps its data. Changing a plugin = bump its `plugin.json` version and add its CHANGELOG entry
- /admin keeps the GaiaCP brand; the company logo shows only in the client area, payment notices and client emails
- Secret settings are stored encrypted and never sent back to the browser (a blank field keeps them)
- Every Filament page and resource names its area through `LimitedToAdminArea` (`AdminAccessTest` checks it)
- New editable setting: default in `config/gaiacp.php` (or the plugin's `config.php`), key in `Settings::KEYS` (or the
  plugin's `settingKeys()`), and in `keys()` of exactly one `SettingsFormPage`
- Admin-bound emails respect each admin's Receive system emails switch; a new daily command goes in
  `SchedulerMonitor::DAILY_COMMANDS`; a new API client adds `ModuleLogger::middleware()`
- A client-area feature updates the client guide in both languages (`resources/docs/client-area/<locale>.md`)
- An admin-facing feature updates its chapter of the admin guide (`docs/admin-guide/en/`, shown in System → Help;
  translated chapters under `docs/admin-guide/<locale>/` too)

## Where things are documented

Read the file for the area you are changing before you change it; don't read them all up front.

- `docs/architecture/stack.md`: admin and client panels (client area pages, orders, public cart, sign up, terms,
  maintenance mode), users and access levels, 2FA, password reset, login security and IP bans, proxies, captcha,
  branding, admin themes and dashboard, notifications bell, languages
- `docs/architecture/code-layout.md`: enums, actions, invoices (payment notices), reminders, reports, tax, Filament
  resources, backups, plugins and the plugin installer, Stripe, money and currencies, domain names, server monitoring,
  ΑΑΔΕ, system health, WHMCS import, debugging logs, keyboard shortcuts, the Settings cluster, settings storage, email
  templates, releases, installer, updates
- `docs/architecture/provisioning.md`: cPanel, Plesk, HestiaCP, registrars and TLD pricing, Email Notifications,
  easy.gr, servers (capacity, panel login, costs)
- `docs/admin-guide/`: the admin guide (how admins use the panel), also System → Help
- `docs/writing-plugins.md`: writing a plugin
- `docs/demo-site.md`: the public demo (`GAIACP_DEMO`, `App\Support\Demo`, the Demo plugin, resets, Plesk set-up)
- The maintainer's notes (preferences, decisions, what is untested) and the backlog are kept outside the repository

## Commands

- `php artisan test`: test suite (PHPUnit, in-memory SQLite)
- `./vendor/bin/pint`: code style (Laravel preset); run before committing
- `php artisan migrate`: apply migrations
- `php artisan schedule:run`: the daily automation; production runs it every minute from cron
- `php artisan gaiacp:send-reminders --dry-run [--date=Y-m-d]`: preview the daily run (payment notices created and
  emailed, domain renewal notices)
- `php artisan gaiacp:suspend-overdue --dry-run [--date=Y-m-d]`: preview auto-suspension (Settings → Suspend hosting after)
- `php artisan gaiacp:terminate-scheduled --dry-run [--date=Y-m-d]`: preview services marked Auto-terminate at the end of
  the cycle (terminated once `next_due_date` is reached; they get no more payment notices)
- `php artisan gaiacp:cancel-unpaid-orders --dry-run [--date=Y-m-d]`: preview pending orders cancelled for being unpaid
  (Settings → Cancel unpaid orders after; never one with a payment or an item set up)
- `php artisan gaiacp:send-client-messages`: send the next emails of Clients → Messages to clients (every minute, Settings →
  Automation → Messages per minute; each recipient once)
- `php artisan gaiacp:compare-servers --dry-run`: what the nightly server comparison would report as new (Servers →
  Compare with the server; Settings → Automation)
- `php artisan gaiacp:daily-summary --dry-run`: what the admins' daily summary email would report, and to whom
- `php artisan gaiacp:health [--connections]`: the System health checks; fails when one is a problem
- `php artisan gaiacp:disable-2fa <email>`: turn off two-factor authentication for an admin who lost their app and
  recovery codes
- `php artisan gaiacp:registrar-check <domain>`: read-only check of a registrar login
- `php artisan gaiacp:backup`: make a backup now and send it as Settings → Backups says (scheduled when enabled there)
- `php artisan gaiacp:prune-logs`: delete module / email log rows older than Settings → Logging keeps them (daily at 03:30)
- `php artisan gaiacp:check-monitors`: read every linked server's status from its monitoring service (every 5 minutes)
- `php artisan gaiacp:sync-domains`: read every managed domain's expiry and nameservers (daily at 04:00)
- `php artisan gaiacp:check-certificates [--dry-run]`: read SSL certificates waiting to be issued; reissue one ending inside
  a longer order, renew an order ending when the service is paid beyond it (hourly)
- `php artisan gaiacp:check-usage [--dry-run]`: warn hosting clients near their package's disk or bandwidth limit
  (Settings → Automation → Usage warning, daily at 06:00, once a month per account and kind)
- `php artisan gaiacp:close-tickets [--dry-run]`: close support tickets that waited on the client longer than Settings →
  Support → Close tickets after (daily at 07:00, Support Ticket Closed email)
- `php artisan gaiacp:lead-follow-ups [--dry-run]`: ring the bell for the open leads whose next contact day has come
  (daily at 08:00, once per contact day; the assigned admin, else everyone in Sales)
- `php artisan gaiacp:expire-quotes [--dry-run]`: mark the sent quotes past their valid until date as Expired (daily at 00:20)
- `php artisan gaiacp:fetch-ticket-mail`: read the support mailbox (Email to ticket plugin, every minute): answers join
  their ticket, new emails open one
- `php artisan gaiacp:send-webhooks`: send the webhook deliveries that are due (Webhooks plugin, every minute; retries,
  then deletes old deliveries)
- `php artisan gaiacp:send-chat-messages`: post the queued Slack / Discord messages (chat plugins, every minute)
- `php artisan gaiacp:check-sites`: DNS and HTTPS of every active hosting account's domain (Site checks plugin, daily at 05:00)
- `php artisan gaiacp:check-plugin-catalogue`: read the plugin catalogue and ring the bell once per new version of an
  installed plugin (daily at 05:40; Settings → Plugins → Plugin catalogue, `docs/plugin-catalogue.md`)
- `php artisan gaiacp:import-whmcs --dry-run [--host= --database= --username= --password= --hash=] [--without=domain ...]`:
  import from a WHMCS database (only read); the dry run lists counts and problems and saves nothing
- `php artisan gaiacp:install [--no-interaction --db-connection= ... --admin-email=]`: set up a new install (same as `/install`)
- `php artisan gaiacp:update [--check]`: update to the latest release (backup first); `--check` only looks
- `php artisan migrate:fresh --seed`: reset the dev database with demo data (admin@example.com / password)
- `php artisan gaiacp:demo-reset [--rebuild]`: the public demo only (`GAIACP_DEMO=true`, `docs/demo-site.md`): put its
  made-up data (`DemoSeeder`) back; every `GAIACP_DEMO_RESET_MINUTES` from the scheduler

## Domain rules

- **Payments are recorded, never charged by GaiaCP.** An admin records them by hand, or a payment plugin records what
  the gateway confirmed (Stripe Checkout only: no card data, saved cards or automatic charges here); recording one
  advances the service's `next_due_date` by one billing cycle and unsuspends it if it was suspended for non-payment.
- **No legal invoices.** Invoices with myDATA transmission are issued in the accounting tool.
  GaiaCP only tracks amounts owed, due dates and payments. Its "invoices" are payment notices (proformas, numbered
  `PF-2026-0001`); call them that in the UI and emails, never τιμολόγιο.
- Money is stored as **integer cents** with an explicit currency (EUR is the base; others under Settings → Currencies);
  never floats. A service, its notices and payments stay in the currency it was created in.
- Every automated action (reminder sent, suspension, API call) is logged via `ActivityLog::record()`
  so the daily job can be audited and is **safe to re-run**: check state before acting, never act twice.
  Reminders are deduplicated by the unique `(service_id, due_date, kind)` key on `reminders`, payment notice emails
  by `invoices.emailed_at` and the unique `(invoice_id, kind)` key on `invoice_reminders`; a service's due date is on
  at most one notice that is not cancelled.
- A one-time service (`BillingCycle::OneTime`, other services only: `BillingCycle::optionsFor()`) is billed once:
  its due date never moves, and once set up and paid it is `Completed` (`Service::completeIfPaidOnce()`), which is
  not billable, so it gets no more notices, reminders or suspension. A quote's one-off lines are ordered as these.
- Renewals are anchored to the start date's day of month (`Service::anchorDay()`), so a service
  started on the 31st returns to the 31st after a short month.
- Clients: `name` is a read-only accessor (first + last); query, sort and search on `first_name` /
  `last_name`. The structured address is the registrant contact for domains. `renewal_reminders` and
  `overdue_notices` gate the two kinds of reminder (`Client::wantsReminder()`).
- Products only pre-fill a new service; the service keeps its own copy of price, cycle, server and package. A hosting
  product has several `servers` (`product_server`, same package on each); `Product::pickServer()` gives the least full
  active one (pending accounts count, full ones skipped, null when all are full), used by New order, the service form,
  `ClientOrder`, Complete order and `SetUpService` (for an item with no server yet). A product's
  `prices` JSON holds `{cycle: {enabled, price_cents, setup_cents}}` (`Product::priceFor()` / `setupFeeFor()`);
  `billing_cycle` is the default cycle. A product's `product_group_id` (Settings → Product groups) only groups it on the order
  pages and the public home page (`App\Support\OrderCatalogue`). `hidden` keeps it out of New order (`orderable()`), `retired` out of every
  picker (`selectable()`); a setup fee goes into the item's `first_payment_cents`. `client_visible` ("Show in client
  area", on by default) is copied to a new service (on without a product) and editable there; the client area shows
  only `Service::clientVisible()` (read-only, Maintenance included) and offers only `Product::clientOrderable()`, which
  leaves out types that are not `ServiceType::clientOrderable()` (Maintenance: an add-on only the admin sets up).
  Product options (`ProductOptionGroup`, `App\Support\ProductOptions`) are kept on the service (`services.options`,
  with their price then) and already included in its `price_cents`.
  An add-on product (`products.addon`) is never ordered alone: `AddAddon` makes it a service with `parent_service_id`,
  the parent's cycle and due date, and `AddAddon::follow()` keeps its status with the parent's.
  `ChangeProduct` (upgrade / downgrade) moves a service to another product of its type; its "charge the difference now"
  notice line (`fee = 'upgrade'`) is the one payment that leaves `next_due_date` where it is.
- Domains are services of type `domain` (billing, reminders, payments as usual) with a 1:1 `domains` row for the
  registrar side (`Service::domainDetails()` / `registration()`): chosen `registrar_id` (null = the TLD's
  registrar), `registration_type`, encrypted `epp_code`, `nameservers`, `expires_at` from sync. A domain's "Auto renew"
  is `!services.auto_terminate` (`Service::autoRenew()`), defaulting from Settings → Domains. The domain name
  stays `services.domain` and is also its label (no label of its own; `Service` fills it in). Sync never moves `next_due_date`. A payment renews only when the known expiry falls
  before the newly paid-through date, so the first payment after registering does not renew twice; tracked
  (`external`) domains are never renewed or synced.
- Orders (`orders`) group services: the items are the services themselves (`services.order_id`), created pending
  by `PlaceOrder`; `services.first_payment_cents` holds a domain's registration/transfer price when it differs
  from the renewal price. `CompleteOrder` activates domains, then hosting, then the rest, and is safe to re-run;
  its window (`ViewOrder`) picks each pending domain's registrar and hosting's server / package, stored first by
  `CompleteOrder::assign()` (pending items of that order only, active choices, logged `order.item_changed`).
  `services.auto_setup` (copied from the product's, `AutoSetup`) says when a pending item is set up: on order
  (`PlaceOrder`), on the first payment (`RecordPayment`, outside its transaction), on Complete order (default) or
  never. All of them go through `SetUpService`, which completes the order once nothing is pending.
- Provisioning is idempotent: `ServiceLifecycle` checks `accountState()` on the panel before acting, and a
  failed call logs `provisioning.failed`, leaves the service unchanged and throws `ProvisioningException`.
  `RecordPayment` unsuspends outside its transaction, so a panel failure never loses the payment.
- Suspend / Unsuspend check the panel's `accountState()` whatever the stored status, since the status field can be
  edited by hand; the Account menu offers both on a hosting account with a server. `override_suspend_until` exempts
  a service from auto-suspension until that date.
- Records with history are not deleted: clients with services, servers with services and services
  with payments hide their Delete action. Terminate or cancel instead.
- A service's client is fixed once it leaves Pending; Move to another client (`TransferService`) changes it, taking its
  unpaid lines off the old client's notices. Payments keep the client who made them (`payments.client_id`).
- Server credentials and API tokens are stored **encrypted** (Laravel `encrypted` cast), never
  in plain columns or logs.
- Tests never hit real servers: `Tests\TestCase` calls `Http::preventStrayRequests()`; use the
  `Tests\FakesWhm` / `Tests\FakesPlesk` / `Tests\FakesHestia` / `Tests\FakesEasy` traits (in-memory panels) or `Http::fake()`.
- Service passwords are shown to the admin on the service form (revealable) but hidden from
  arrays/JSON; a blank field keeps the stored value.

## Conventions

- Every text in the panel goes through `__()` with the English text as the key; values go in as `:placeholders`
  (`__('Sent to :email', ['email' => $to])`), never by joining pieces. Filament labels (`->label()`, and the ones
  it makes from field names) are translated already (`translateLabel()` in `AppServiceProvider`); static titles and
  menu labels need `App\Filament\Concerns\Translates*` on the class. `LocalesTest` fails on untranslated text in
  `app/Filament` and on any text missing from `lang/el.json` (add new ones with `gaiacp:translations el --write`, then
  translate them). Activity log messages stay in English

- Validate input with Form Requests; authorize every admin route (auth middleware).
- Keep panel/registrar API calls out of controllers: controllers call a service class,
  which calls the provisioner.
- Add a test with every feature or bug fix.
