# Code layout

Where each part of the app lives (enums, actions, invoices, reports, plugins, monitoring, settings, mail, releases, installer, updates). Read before changing that part.

- `app/Enums/`: `BillingCycle` (owns due-date arithmetic; `OneTime` = billed once, other services only via
  `optionsFor()`, `advance()` keeps the date, `recurring()` lists the rest), `ServiceStatus` (`Completed`: a one-time
  service set up and paid, `Service::completeIfPaidOnce()` from `SetUpService` and `RecordPayment`; not billable), `ServiceType` (hosting, domain,
  maintenance, other; only hosting `needsServer()`), `PaymentMethod` (the built-in cash / other only), `ClientStatus`
- `app/Actions/`: `DomainLifecycle` (registrar calls: activate = register / transfer / track only, renew, sync,
  nameservers, EPP), `PlaceOrder` / `CompleteOrder` / `RecordOrderPayment` (admin orders), `CancelOrder` (Cancel
  order, and `gaiacp:cancel-unpaid-orders` daily for pending orders with no payment and nothing set up, Settings →
  Automation), `RecordPayment`, `ReversePayment` (the only code that moves `next_due_date` after creation) and
  `ServiceLifecycle` (create / suspend / unsuspend / terminate / change package: the only code that calls a
  provisioner or changes status through automation; `create()` also runs on an active or suspended service, for one
  moved to another server: it creates the account there, suspends it again if needed and leaves the old server alone)
- `app/Provisioning/`: `HostingProvisioner` and `ServerModule` (a server type, from a plugin) interfaces, `ProvisionerFactory`,
  `DomainRegistrar` and `RegistrarModule` (a registrar API, from a plugin), `RegistrarFactory`, `UsernameGenerator`, `PackageOptions`
  (cached package suggestions)
- `app/Reminders/`: `ReminderPlanner` (which reminder is due, pure logic) and `SendRenewalReminders` (now only the
  Upcoming Domain Renewal and Expired Domain notices)
- `app/Invoices/`: payment notices (proformas; `Invoice` / `InvoiceItem` models, `invoices` tables). `GenerateInvoices`
  creates one per client and due date `invoices.days_before` ahead, plus one per pending order for its unpaid
  items (`InvoiceOrder`, run by `PlaceOrder`, emailed at once; `invoices.order_id`; cancelling the order cancels it); `SendInvoiceEmails` sends Created, the payment
  reminders (`before_days` after it was emailed), First overdue (first `overdue_days`) and Second overdue (later ones).
  `RecordInvoicePayment` pays every line through `RecordPayment`, which marks the line (`payment_id`) and settles the
  notice; `ReversePayment` reopens it. A price change (`App\Actions\ChangePrice`, `Filament\Actions\ChangePriceAction`)
  sets `services.new_price_cents` / `new_price_from` (from no sooner than `automation.price_change_notice_days`) and
  emails Price Change Notice per client; `GenerateInvoices` bills `Service::priceOn($due)` and, once a notice for a due
  date on or after it exists, `ChangePrice::apply()` makes it the service's `price_cents` (logs `service.price_changed`).
  `Invoice::withdraw()` takes a service's unpaid lines off its open notices
  (cancelling a notice left empty): for Auto-terminate only lines due from today, and when a service is terminated or
  cancelled by hand (`Service` updated hook) the overdue ones too
  A notice keeps the client's name, address and VAT details from the day it is issued (`invoices.bill_to`,
  `Invoice::BILL_TO`, filled on create, plus `custom`: the client fields marked Show on payment notices, as shown
  that day); an unpaid one takes the current ones by the admin's Update client details
- `app/Invoicing/`: invoices, optional (Settings → Invoices, `invoicing.enabled`, `invoicing.number_format`).
  `IssueInvoice` is called by `Invoice::settle()` when a notice becomes paid (a failure is logged `tax_invoice.failed`
  and never undoes the payment; Issue invoice on the notice retries) and copies it into `tax_invoices` /
  `tax_invoice_items` (seller from Settings → Company, `bill_to`, lines, tax, payment methods), one per notice (notice
  row locked), none for "Add funds" or zero notices. `InvoiceNumbers::take()` gives the next number of the series
  (`invoice_series`: `invoice:<year>` when the format has `{YEAR}`, else `invoice`) under a row lock in the same
  transaction; `continueFrom()` moves it forward only. `TaxInvoice` / `TaxInvoiceItem` throw on update and delete; admin
  pages `TaxInvoiceResource` (`/admin/tax-invoices`, read only, in the menu once on or while any exist)
- Client fields (Settings → Client fields, `ClientFieldResource`): `client_fields` rows (`key` fixed once created,
  `labels` per locale, `ClientFieldType`, `options` for a list, `required`, `ClientFieldAccess` hidden / shown /
  editable, `on_notice`, `sort`); values in `clients.custom_fields` {key: value}. `App\Support\ClientFields` (scoped,
  cached per request) builds the form fields (`custom_fields.<key>`) for the client form, sign up (editable only) and
  the client's profile (editable saved at once, shown read-only), cleans submitted values and makes the notice lines.
  A deleted field's values stay on the clients (admin edits merge into them) but show nowhere
- `app/Credit/`: client credit. `ClientCredit::record()` is the only code that changes `clients.credit_cents` (client row
  locked, never below zero) and writes the `credit_transactions` ledger (`CreditType`: deposit, applied, refund,
  adjustment; `balance_cents` after each). `AddFunds` makes an "Add funds" notice (`invoices.credit`, one
  `invoice_items.credit` line with no service, no tax, due in 14 days, one open per client; `cancelStale()` in
  `gaiacp:send-reminders`); `RecordInvoicePayment` pays that line into the balance (`invoice_items.credit_transaction_id`,
  in one transaction), so admin recording and Stripe work unchanged. `ApplyCredit` pays notice lines the balance covers
  in full (a cycle line with its late fees, stale lines skipped, notices in another currency or "Add funds" ones never)
  through `RecordPayment` with method `credit` (`ClientCredit::METHOD`, "Account credit", not offered when recording
  by hand); `ReversePayment` returns it. Auto (Settings → Client credit → `credit.auto_apply`): daily before the notice
  emails, in `InvoiceOrder` before the order's email, and after a deposit. `InvoiceItem::scopeUnpaid()` = a service line
  or a credit line not paid yet (`Invoice::settle()` and `RecordInvoicePayment::unpaid()` use it). Admin: Settings →
  Client credit, the client page's Credit menu / tab / summary box, "Pay with credit" on a notice; client area:
  `Client\Pages\Credit` (balance, Add funds, history) and "Pay with credit" on a notice
- `app/Reports/DailySummary.php` + `gaiacp:daily-summary` (`DailySummaryMail`, daily right after `gaiacp:send-reminders`):
  the admins' daily email, on/off under Settings → Automation (`gaiacp.automation.daily_summary`), to active admins with
  `users.system_emails` on (Admin users → Receive system emails, the switch for every admin-bound email): payment notices created since
  the last one (`admin_summary.sent` in the activity log, once a day), every overdue notice, and domain syncs that
  changed something (`changes` on `domain.synced`) or failed plus a count of the rest. Nothing to report = no email
- `app/Reports/` + `app/Filament/Clusters/Reports/`: Reports (`/admin/reports`, a cluster laid out like Settings: cards by
  `ReportsCluster::GROUPS`, a page joins with `$cluster`, a group and `ReportCard::reportDescription()`). `IncomeReport`
  (payments as recorded, grouped in PHP so SQLite and MySQL agree) and `IncomeForecast` (each billable service's price on
  each due date ahead, auto-terminate left out, past cycles = overdue) feed the `IncomeReportPage`s (a table built by
  `report()`, `resources/views/filament/reports/table.blade.php`). `ServiceReportPage` is the WHMCS-style custom report
  (Domains, Services): `fields()` lists each field once and builds both the toggleable columns and the CSV, which
  exports the filtered, sorted rows with the columns shown. `Csv::download()` writes UTF-8 with a BOM.
  `SalesOpportunities` (the report class: `ids($kind)` for transfer / hosting / winback, a TLD without grace days counts
  30) feeds the page of that name (`HasTabs`, a tab per kind; Email sends the kind's template through
  `ClientMailer::send(…, text:)` with the admin's edited text; "Emailed" is the last `email.sent` log with that
  template). `ClientHealth` is the client page's "Payment record" box (`ClientSummary`): delay = paid on − the due date
  it covered (early = 0, one per notice), plus open overdue notices' days so far; thresholds are its constants
- `app/Support/Tax.php`: tax rules (Settings → Tax, `gaiacp.tax`): exclusive or inclusive, country rule beats the
  all-countries one, `clients.tax_exempt`. A notice keeps the rate it was created with. An enabled plugin's
  `Plugin::taxExemption($client)` drops the rule (`Tax::ruleFor()` null) and gives the reason (`Tax::exemptionFor()`),
  which `Invoice` stores as `invoices.tax_note` when the notice is created (printed on it, in `{$invoice_tax}`, on the admin view)
- `app/Filament/`: resources (Clients (`EditClient`: `ClientSummary` widget over tabs = the form + relation managers
  keyed hosting / domains / other / orders / notices / payments / activity, each `canViewForRecord()` by its resource's
  area; a select on phones, `filament/clients/tabs-select`), Payment notices (every notice, newest first; `Invoices\Concerns\HasStatusChips`
  = All / Unpaid / Paid / Cancelled / Overdue chips over the list and the client tab), Orders (`Orders\Concerns\HasStatusChips`
  = All / Pending / Completed / Cancelled chips, the same way), Services shown as Hosting / Domains / Other services tabs (`ListServices`:
  a title, description and count per tab, `quickFilters()` chips that set the status / `due_soon` filters, Export CSV;
  `ServicesTable` is shared with the client page, order items and the dashboard), read-only
  Payments and Activity log in the sidebar; Products, Servers, Registrars, Email templates and System health in the Settings cluster), shared actions (`RecordPaymentAction`, `ServiceLifecycleActions` =
  the "Account" menu, `DomainActions` = the "Registrar" menu, `TestConnectionAction`),
  dashboard widgets
- `app/Backups/`: `RunBackup` (SQL dump → zip, AES-256 with Settings → Backups' zip password → kept in
  `storage/app/private/backups`, newest `keep` → FTP via `FtpUploader` (ext-ftp, FTP/FTPS) → `BackupMail` to the admin;
  each copy fails on its own and is logged as `backup.created` / `backup.failed`), `DatabaseDumper` (pure PHP,
  MySQL/MariaDB and SQLite, no mysqldump needed; `RunBackup::LOG_TABLES` are written without rows unless
  `backups.include_logs`). Scheduled in `routes/console.php` only when enabled. System health → Biggest tables is
  `App\Support\Health\TableSizes` (information_schema on MySQL, dbstat on SQLite)
- Plugins: `app/Plugins/` has `Plugin` and `PluginManager` (singleton; `BUILT_IN` = cPanel, Plesk, HestiaCP, DirectAdmin, CyberPanel (off by default), easy.gr, Enom, Email Notifications, ΑΑΔΕ, VIES VAT check, WHMCS
  import, Bank transfer, Stripe, Google sign-in, Google reCAPTCHA, Cloudflare Turnstile, Uptime Kuma, UptimeRobot, HetrixTools, Better Stack, IP blacklist check, Site checks, Gaia and Flat themes; `plugins.enabled`, no row = on
  for a bundled one unless `Plugin::enabledByDefault()` is false (easy.gr, ΑΑΔΕ, VIES; `GAIACP_PLUGINS_START_ENABLED` lists
  ones to start on anyway), off for others; `recordVersions()` writes the row after migrate, so this only decides a new
  install or a newly bundled plugin). Other plugins: `PluginManager::discover()` registers every `plugins/<Folder>/plugin.json`
  (its `class`, else `<Folder>Plugin`; failures in `problems()`), and `App\Plugins\PluginInstaller` (Settings → Plugins
  → Upload plugin, password + trust tick; `gaiacp.plugins.uploads`) checks a zip, unpacks it in storage, moves it in,
  runs its `database/migrations` (also loaded for every `migrate`), updates only to a newer version (old folder kept,
  restored on failure) and uninstalls a disabled non-bundled one (delete data = `migrate:reset` of its path +
  its settings). `App\Plugins\Catalogue\Catalogue` reads the signed plugin catalogue (plugins.gaiacp.digital, built by the
  gaiacp-plugins repository; Ed25519 + SHA-256, `gaiacp.plugins.catalogue`): `docs/plugin-catalogue.md`. Settings → Plugin catalogue
  (`Filament\Pages\PluginCatalogue`) installs / updates through `CatalogueInstaller` → `PluginInstaller`;
  `gaiacp:check-plugin-catalogue` (daily) logs `plugin.update_available` once per version (bell, super admins). Guide: `docs/writing-plugins.md`. Each plugin is a folder in `plugins/<Name>/` (namespace `Plugins\<Name>\`) with
  its code, `plugin.json` (`version` x.y.z and `requires` = oldest GaiaCP it runs on; older core = `PluginState::Incompatible`,
  counted as off), `CHANGELOG.md` (an entry per version, shown on the Plugins page; a test checks the current one), an
  optional `config.php` (dotted key => defaults, merged into config, existing values win) and `lang/<code>.json`
  (its texts the app's `lang/` doesn't have) and `views/` (view namespace `<plugin id>::`). `PluginManager::recordVersions()` runs after every `migrate` (and on the
  Plugins page): `plugins.version` / `previous_version` / `version_changed_at`, a change logged as `plugin.updated`.
  Changing a plugin = bump its `plugin.json` version and add its CHANGELOG entry. A plugin can be a `ServerModule` (`servers.type` = its key: provisioner,
  ports, form help and extra fields) or a `RegistrarModule` (`registrars.module`: registrar, default TLDs, login fields)
  or a `App\Payments\PaymentModule` (`payments.method` / `clients.default_payment_method` = its key, label, instructions),
  and adds `pages()`, `commands()`, `settingKeys()` / `encryptedSettingKeys()`, `moduleLogs()` and
  `clientVatNumberActions()`. Settings → Plugins (`Pages/Plugins`) enables / disables them; bundled plugins are never
  uninstallable (project rule). Off: the factories throw `ProvisioningException`, pickers offer only enabled types (an
  existing record keeps its own), the plugin's pages deny access. A new server panel, registrar or integration = a new
  plugin folder listed in `BUILT_IN`. Payment methods are plugins (project rule): `App\Payments\PaymentMethods` lists the
  enabled ones plus cash / other (`options()`, `default()`, `label()` for any stored key) and joins their `instructions()`
  into `{$payment_instructions}`; `plugins/BankTransfer` keeps `gaiacp.payment_instructions` (Settings → Bank transfer)
- `plugins/Stripe/`: card payments. `Plugin::clientPaymentNoticeActions()` puts "Pay by card" on the client's unpaid
  notice (`StripeCheckouts::canPay()`: unpaid, no stale line, the mode's secret key set) → `StripeCheckouts::start()`
  opens a Checkout Session (`StripeApi`, plain HTTP + `ModuleLogger`, no SDK) for `RecordInvoicePayment::unpaid()` and
  keeps a `stripe_checkouts` row. `complete()` records it through `RecordInvoicePayment` (method `stripe`, reference =
  PaymentIntent) once Stripe says paid: from the signed webhook (`Plugin::webhookRoutes()` → `routes/webhooks.php`,
  `/webhooks/stripe`, no web middleware, `WebhookSignature`) or Stripe's API on the client's return
  (`filament.client.stripe.return`), never from the visit itself. A row is claimed open → processing once, so a repeat
  report records nothing; a payment that no longer fits its notice becomes `unmatched` (`payment.online_unmatched`,
  listed in the daily summary). Settings → Stripe (super admins): mode test / live, each with its secret key and
  webhook signing secret (encrypted). `Plugin::paysOnline()` keeps it from being the pre-selected method by hand
- `app/Support/Money.php`: cents ↔ display/form conversion; `format()` writes a currency as Settings → Currencies says
- Currencies: `currencies` (`Currency`: code, prefix, suffix, `CurrencyFormat`, `rate` = units per 1 base) read once per
  request by `App\Support\Currencies` (base = `gaiacp.currency`, rate 1, never deleted; `fromBase()` / `toBase()` /
  `sumToBase()` at today's rate for totals, the dashboard and reports). `clients.currency` (`Client::currencyCode()`, fixed
  once it `hasBilling()`) is what `PlaceOrder`, `ClientOrder` and the admin order / service forms price and bill in;
  products' and TLDs' `currency_prices` hold other currencies' prices (`priceFor($cycle, $currency)`,
  `priceCents($kind, $years, $currency)`; missing = base × rate). Settings → Currencies (`CurrencyResource`):
  Update exchange rates = `ExchangeRates::update()` (ECB daily XML, crossed through EUR; `gaiacp:update-exchange-rates`,
  daily at 17:00 when Settings → Automation says so), Update product prices = `updatePrices()` (stores base × rate)
- Product options: `product_option_groups` (`ProductOptionGroup`, Settings → Product options = `ProductOptionGroupResource`)
  hold `options` JSON ({id, name, type select / radio / yesno / quantity, hidden, min, max, choices: [{id, name,
  prices: {cycle: cents}, setup_cents}]}, base currency; the ids are set on save and never change), attached to products
  through `product_product_option_group` (`Product::optionGroups()`). `App\Support\ProductOptions` prices a pick (a cycle
  without its own price = monthly × months, `fromBase()` for other currencies) and builds what `services.options` keeps
  (`select()`: name, value, quantity, price_cents, setup_cents at that time; a pick unchanged keeps its old price).
  `services.price_cents` already includes them, so renewals, reminders and price changes need nothing else; the
  admin forms add them live (`App\Filament\Forms\ProductOptionFields`, `options.{id}` fields; the service form changes
  the price by the difference) and `GenerateInvoices::describe()` names the ones not hidden. `PlaceOrder` keeps picks
  only when the item passes `options`. An option without a pick takes its first choice, except a hidden one. In the
  client area `ClientOrder::items()` takes only the options not hidden (`for($product, hiddenToo: false)`), refuses a
  choice the option doesn't have, and prices them; the cart keeps the picks; order emails and the client's service
  page list them
- Add-ons: `products.addon` + `product_addon` (`Product::addonFor()` / `addons()`, `scopeAddonsFor($product)`: those naming
  it or naming none; `orderable()` leaves add-ons out) and `services.parent_service_id` (`Service::parent()` / `addons()`).
  `App\Actions\AddAddon::handle()` (service page → Add-ons, `AddonsRelationManager`) copies the parent's client, cycle,
  due date, domain and currency; pending parent → pending in its order, else its status from today, first owed on the
  parent's next due date with the setup fee in `first_payment_cents`. `AddAddon::follow()` runs from `Service::updated`
  on a status or `auto_terminate` change (ended / suspended / unsuspended with it, logged `service.addon_followed`);
  `TransferService` moves them too. Clients add them with `App\Actions\ClientAddons` (client service page → "Add an
  add-on", `Client/Actions/OrderAddon`; `config('gaiacp.client_area.orders')`, active parent not ending, add-ons with
  `client_visible`, not hidden, needing no server, priced for the parent's cycle, not already on it): a pending service
  with no order (so `GenerateInvoices` skips it) and a notice due today with one `fee = 'upgrade'` line, the days to the
  parent's due date prorated over its cycle plus the setup fee. `RecordInvoicePayment::payUpgrade()` (also used by
  `ApplyCredit`) then calls `ClientAddons::activate()`; nothing owed = active at once. Logged `service.addon_ordered`
  (bell, event); its `invoice.created` carries `client_addon` so the bell rings once. On the Order page and the public
  cart (`OrderForm`, field `addons`, kept in the cart) the add-ons of `ClientAddons::offered($product, $cycle, $currency)`
  are ticked with the product: `ClientOrder::items()` adds an item each with `parent => 0`, and `PlaceOrder` sets
  `parent_service_id` (and the parent's domain) from that earlier item, so they are pending in the same order
- Upgrade / downgrade: `App\Actions\ChangeProduct` (service page → Price → Upgrade / downgrade, `ChangeProductAction`)
  moves an active or suspended service to another product of the same type (add-on to add-on, not retired, not domains):
  `ServiceLifecycle::changePackage()` first (a panel failure changes nothing), then product, label (when it was the old
  product's name), `price_cents`, `options` (`ChangeProduct::options()`: same picks where the product has them) and
  clears a scheduled price change; logs `service.product_changed`. The unpaid line already issued for the next due date
  is repriced (`App\Invoices\RepriceIssuedNotice`, `invoice.line_repriced`; also after a save of the service form, so
  an unpaid order's notice and total follow a new price; there a First payment field shows until the first payment, and
  one left as it was moves by the price's difference). "Charge the difference now" prorates by days left until `next_due_date` over the
  cycle's days (`ChangeProduct::difference()`): positive → a notice due today with one `fee = 'upgrade'` line
  (`InvoiceItem::UPGRADE`, `due_date` today, `period_end` the due date), paid by `RecordInvoicePayment::payUpgrade()` (a
  payment with `due_date_before` = `due_date_after`; also from credit in `ApplyCredit`); negative → an Adjustment into
  client credit when it is on and in the client's currency. `InvoiceItem::forCycle()` (not upgrade lines) is what
  `RecordPayment` marks, `GenerateInvoices` dedupes on and `Invoice::withdraw()` takes off for Auto-terminate
- `app/Support/DomainName.php`: domain names in (`normalize()`: lowercase, IDN stored in its own letters) and out
  (`toAscii()`: punycode for registrars and cPanel); `App\Rules\ValidDomain` refuses IDN unless Settings → Domains allows it.
  `app/Support/DomainSearch.php`: the client area's domain search (`Client/Pages/SearchDomains`, in the menu right after Domains): the typed
  name with each TLD that has a register price (`candidates()`, at most 10), and `check()`: the TLD's registrar if it
  implements `ChecksAvailability`, else RDAP (IANA's `dns.json`, kept a day; 404 = free), else DNS nameservers (none =
  `DomainAvailability::Unknown`, "probably available"); answers kept 10 minutes as plain strings, 20 searches a minute
  per client, module log `domain_search`; `DomainSearch::forOrder()` gives the order forms (`App\Filament\Forms\DomainAvailabilityHint` on the client and admin domain fields) the answer, or null past the limit (`limiterKey()`, shared with searches; not for admins) or on an error, and `ClientOrder::addToCart()` refuses registering a Taken one or transferring an Available one; `app/Support/DomainSsl.php`: Settings → Domains → SSL with domains
  (`domains.ssl_product`, `domains.ssl_discount` % off the first period only, as `first_payment_cents`), the cart
  line's `ssl` flag on a domain on its own adds that item in `ClientOrder::items()`; the captcha form `domain_search` asks before the first search, then a solved
  one lasts `SearchDomains::VERIFIED_MINUTES` (session `domain_search.verified_until`). Tests stub DNS with `resolveNameserversUsing()` (`Tests\TestCase`).
  `app/Invoices/RenewFreeDomains` (price 0 domains, a 0 payment when due; needs active hosting when that setting is on)
  and `AddDomainLateFees` (TLD grace / redemption fees as `invoice_items.fee` lines, paid with their cycle's line)
  run in `gaiacp:send-reminders`
- Server monitoring: `app/Monitoring/` `MonitoringModule` (from a plugin, `Plugin::monitoringModules()`: `monitors()` = every
  monitor's `Monitor` with its `MonitorStatus`, one call) and `ServerMonitoring` (`gaiacp:check-monitors`, every 5 minutes, and
  Servers → Check status now): copies `servers.monitor_status` from the monitor in `monitor_module` / `monitor_ref`, logs
  `server.down` / `server.up` (bell, super admins) and `monitoring.failed` once until the service answers; another monitor
  resets the status. Each check is a `server_monitor_checks` row (kept `UptimeHistory::KEEP_DAYS`, `gaiacp:prune-logs`);
  `UptimeHistory::for($server, '24h'|'30d')` groups them into the uptime bar's dots (`filament/servers/uptime-bar`, Servers
  list with `ListServers::$uptimeRange`, dashboard ServerCapacity), its % = `servers.monitor_uptime` (the service's ratio
  by window) else the checks. `plugins/UptimeKuma` reads Kuma's Prometheus `/metrics` (`KumaMetrics`; basic auth, API key as the
  password; `monitor_id` = ref, the name on Kuma 1.x; `monitor_uptime_ratio` on 2.x), Settings → Uptime Kuma (`gaiacp.uptime_kuma.*`, key encrypted). `plugins/UptimeRobot`
  posts API v2 `getMonitors` (`RobotMonitors`: id = ref, status 2 up / 9 down / 1, 8 pending / 0 paused = unknown,
  `custom_uptime_ratios=1-30-365`, 50 a page), Settings → UptimeRobot (`gaiacp.uptime_robot.api_key`, a Read-Only key, encrypted).
  `plugins/HetrixTools` reads API v3 `GET /v3/uptime-monitors` with a bearer token (`HetrixMonitors`: id = ref,
  `uptime_status` up / down / maintenance, `monitor_status` paused = unknown, `uptime` % as 30 days, `target` host,
  response time averaged over locations; 100 a page, up to 5 pages; field names read loosely, untested against a real
  account), Settings → HetrixTools (`gaiacp.hetrix_tools.api_token`, encrypted). `plugins/BetterStack` reads Uptime API v2
  `GET /api/v2/monitors` (JSON:API, `BetterStackMonitors`: id = ref, `attributes.status` up / down / maintenance /
  pending, validating = pending, paused = unknown, `url` host; no uptime in the list), 250 a page following
  `pagination.next` only on uptime.betterstack.com, up to 5 pages; Settings → Better Stack (`gaiacp.better_stack.api_token`, encrypted).
  `App\Support\ServerIncidents` (client panel `CONTENT_START`, `filament/client/server-incident`): a client's visible
  billable hosting on an active server whose `monitor_status` is down / maintenance, behind `gaiacp.client_area.incident_notice`
  (+ `_message`). `plugins/Blacklists` (IP blacklist check): `BlacklistCheck` looks up each active server's
  `ip_address` (else its hostname's public IPs) in `gaiacp.blacklists.zones` through `DnsResolver` (tests replace it);
  127.0.0.x = listed (`blacklist_listings` row, logs `blacklist.listed` / `blacklist.delisted`, bell to super admins),
  127.255.255.x or no 127.x = refused (kept as it was). `gaiacp:check-blacklists` hourly via `Plugin::schedule()`
  (`PluginManager::schedule()` from `routes/console.php`; a plugin's command checks itself that the plugin is on).
  `plugins/SiteChecks` (Site checks): `SiteCheckRunner` takes each active hosting service with a domain and server;
  `SiteProbe` (tests replace it) gives the domain's A/AAAA, its nameservers (or its nearest parent's) and the
  certificate on 443 (once with verification, else again without it to say why). `DnsStatus`: ours when an address is
  the server's `ip_address` / `shared_ip` / hostname's, Cloudflare when its nameservers are `*.ns.cloudflare.com`,
  else elsewhere / none / unknown (no server address). HTTPS is checked only when the domain doesn't point elsewhere
  (`SslStatus`: valid, expiring under `gaiacp.site_checks.ssl_warn_days`, expired, wrong name, untrusted, none,
  skipped). One `site_checks` row per service (deleted once it is no longer active); `SiteCheck::problems()` keys not
  in `alerted` are logged together as `site.problems` (bell, Services area). `gaiacp:check-sites` daily at 05:00;
  Services → Site checks (`SiteChecksPage`, problems only by default, Check now, Check all now)
- REST API (`routes/api.php`, `/api/v1`, registered in `bootstrap/app.php`): `api_keys` (`ApiKey`: name, SHA-256
  `token_hash` only (the `gcp_…` key is shown once by `ApiKey::generate()`), `token_prefix`, `permissions` list of
  `<area>.<action>` from `ApiKey::PERMISSIONS`, `allowed_ips` IPs / CIDR (`IpUtils`), `last_used_at` / `_ip`,
  `revoked_at`; never deleted). `AuthenticateApiKey` (alias `api.key`, before throttling in the priority list, so no
  404 or limit before the key): 503 while the installer is open, 401, 403 for the IP, sets `ApiKey::current()` for the
  call; `api.key:<area>.<action>` checks a permission. `throttle:gaiacp-api` (`gaiacp.api.per_minute` per key).
  Controllers in `App\Http\Controllers\Api` call the panel's Actions (`PlaceOrder` with `ClientOrder::cartItems()`
  pricing, `CompleteOrder`, `CancelOrder`, `RecordPayment`, `RecordInvoicePayment`, `ReversePayment`,
  `ServiceLifecycle`, `SendInvoiceEmails`); plain saves log `client.*` / `product.*` / `service.updated` themselves.
  `ActivityLog::record()` stores `api_key_id` (shown as "API: name" under By). Form Requests in
  `App\Http\Requests\Api`, JSON in `App\Http\Resources\Api` (no passwords / EPP codes). `App\Api\OpenApi`:
  `endpoints()` (one list for openapi.json and the docs page; `ApiTest` checks every route is in it with its
  permission) and `spec()`. Settings → API keys (`ApiKeySettings`, Integrations, super admins) and its Documentation
  page (`ApiDocumentation`, `/admin/settings/api-keys/documentation`, download openapi.json). A new call = route with
  its `api.key:` permission + entry in `OpenApi::endpoints()` (+ schema)
- Events: `App\Events\GaiaEvent` is raised for the activity log events in `GaiaEvent::NAMES` (orders, sign ups,
  payment notices, payments, service status changes, cancellation / package change requests, domains, tickets,
  servers, `provisioning.failed`): `ActivityLog`'s `created` hook calls `fromLog()`, which waits for the transaction to
  commit (`DB::afterCommit`, so a rolled-back action raises nothing), then `event()` and
  `PluginManager::dispatchEvent()` (each plugin that is on and lists it in `Plugin::events()`, or `*`, gets
  `handleEvent()`; a failing one is reported and skipped). `payload()` builds plain data once (client, service, and
  the record named by the log's `invoice_id` / `payment_id` / `order_id` / `ticket_id` / `server_id`, plus the log
  properties without password / secret / token / EPP names). A new event = add the log event to `NAMES` and its label
  to `options()`.
- `plugins/Webhooks/`: `webhook_endpoints` (`WebhookEndpoint`: name, url, encrypted `secret`, `events` list or `["*"]`,
  `active`, `failures` in a row, `alerted_at`) and `webhook_deliveries` (`WebhookDelivery`: `uuid`, event, the JSON
  `payload` sent on every try, `DeliveryStatus` pending / sent / failed, attempts, `next_attempt_at`, response code and
  body, error). `handleEvent()` only queues (`WebhookSender::queue()`); `gaiacp:send-webhooks` (every minute) sends what
  is due (`sendDue()`, `gaiacp.webhooks.per_run`), signs `{timestamp}.{body}` with HMAC-SHA256 (`X-GaiaCP-Signature`,
  `-Timestamp`, `-Event`, `-Delivery`), no redirects, `WebhookAddress` (no loopback/private/link-local/reserved address unless `allow_private_addresses`, checked on save and send, connection pinned to the checked IP), `ModuleLogger` module `webhooks`; 2xx = sent, else retried after
  `gaiacp.webhooks.retry_minutes`, then failed. Failed tries in a row: `webhook.failing` (bell, super admins) once at
  `alert_after`, switched off with `webhook.disabled` at `disable_after` (its waiting deliveries fail); a success
  resets them. Deliveries older than `debug.module_log_days` are deleted by the same command. Settings → Webhooks
  (`WebhooksPage`, super admins, with the plugin's On switch): New webhook (secret shown once), Edit, Test (a
  `webhook.test` delivery, sent now, no retries), New secret, Deliveries, Delete; `WebhookDeliveriesPage` (history,
  View, Resend)
- Chat plugins: `App\Chat\ChatPlugin` (a Plugin with `message(ChatLine)` and `urlPrefixes()`; settings
  `<id>.webhook_url` encrypted and `<id>.events`) queues each event it wants as a `chat_messages` row
  (`ChatMessage`, the JSON body built in `config('app.locale')`); `ChatLine::from()` makes the emoji, label, details
  (client · domain · order · ticket · server · amount) and admin link. `gaiacp:send-chat-messages` (every minute,
  `ChatSender`) posts them, retries after 1, 5, 30 minutes, then fails with `chat.failed`, and deletes old rows
  (`debug.module_log_days`). `ChatSettingsPage` is their settings page (On switch, URL checked against the prefixes,
  events, Send a test message). `plugins/Slack` (Block Kit mrkdwn section) and `plugins/Discord` (one embed coloured
  by `ChatLine::level`, mentions off). `plugins/Telegram` is a chat plugin too: its "webhook URL" is the Bot API's
  `sendMessage` built from the encrypted `telegram.bot_token`, and the body carries `telegram.chat_id` (HTML parse
  mode). With `telegram.commands` on, saving the settings calls `setWebhook` (`POST /webhooks/telegram`, secret
  token = HMAC of the bot token with the app key, checked on every update) and `setMyCommands`; turning it off calls
  `deleteWebhook`. `TelegramWebhook` answers only the configured chat (others get their chat ID on /start) with
  `TelegramCommands` (read only, in `app.locale`, logged `telegram.command`), as the webhook response
- AI assistant: `App\Ai\Assistant` is the only caller of an AI service. `improve($text, $style)` (`styles()`) and
  `translate($text, $locale)`, and the writers `productDescription($details, $locale, $draft)` (ProductForm's
  `details()`), `outageNotice(ServerIncidents::now(), $locale)` (public names only) and `domainNames($name, $locale)`
  (labels parsed from the answer; `SearchDomains::suggest()`, `AI_PER_HOUR` per `DomainSearch::limiterKey()`),
  `readQuestion($question, $allowed, $currency)` (JSON `{question, params}` picking one of `App\Ai\DataQuestions`'s
  `catalogue()`, which `run()` answers read-only with checked params and the area each needs; `Reports\Pages\AskData`)
  and `help($question, Help::sectionsFor($question))` (the guide's sections weighted by rare words, `AI_CONTEXT` chars),
  and for tickets `ticketReply()`, `ticketSummary()` (JSON; `tickets.summary`, cleared by the client's next message) and
  `kbArticle()` (JSON title + body, saved unpublished) from an `App\Ai\TicketContext` (public messages only, newest
  first up to `MAX_THREAD`; `redact()` swaps the client's names, company, emails and 10+ digit numbers for
  placeholders, and for an article their domains for example.com; `restore()` puts the first name back; `articles()`
  scores published KB articles by shared words; `services()`), on `Tickets\Pages\ViewTicket`; `reminderText()` (a
  template's text rewritten, JSON; `ViewInvoice`'s Personal reminder sends it through `SendInvoiceEmails::send(text:)`)
  and `clientHints()` (JSON suggestions; `Clients\Actions\ClientHintsAction`) from `App\Ai\ClientFacts` (`payments()`,
  `services()` with domains as "site N", `catalogue()`), and `translateTexts()` (JSON; a translation whose
  :placeholders, `{$placeholders}` or HTML tags differ is dropped) for `LanguageSettings::translateBatch()` (`AI_BATCH`
  texts of `Translations::export()` per request, saved with `Translations::import()`, the next asked by `$wire`),
  build fixed English instructions (keep facts, links, formatting and `{{ }}` / `{ }` /
  `:name` placeholders; Greek in the polite plural; the text in `<text>` tags is never instructions), check the
  feature's switch (`ai.features.<feature>`, `Assistant::FEATURES`), `MAX_TEXT` and `ai.monthly_tokens`, ask the
  provider and keep the usage in `ai_usages` (`AiUsage`: user, feature, provider, model, tokens; never the text).
  The provider is an `App\Ai\AiModule` (`aiKey()`, `aiConfigured()`, `complete(Prompt): Answer`, throws
  `AiException`) from a plugin's `aiModules()`; `PluginManager::aiModules()` lists the ones on and `ai.provider`
  picks one when several are. `App\Filament\Actions\AiTextActions::on($field, clientLanguage:, write:)` (write: items
  that fill the field from elsewhere in the form, `aiWrite`) /
  `forLocale($field, $locale, $nameFor)` put the AI menu after a field's label (each item: `fillForm` asks, a window
  shows the editable suggestion, Use this text sets the field; an error is a toast and the field is untouched).
  Settings → AI assistant is `Pages\AiSettings`. `plugins/Claude`: the Messages API (`anthropic-version`
  `ClaudePlugin::API_VERSION`, effort low, `fallbacks: "default"` on Opus / Sonnet), `MODELS`, Test connection =
  `GET /v1/models/{model}`; key `claude.api_key` encrypted, module log `claude`. `App\Ai\ChatCompletionsPlugin` is the base
  for OpenAI-style services (`POST {baseUrl}/chat/completions` with a bearer key, `max_tokens` at most 8192 (or `tokenLimitField()` / `MAX_TOKENS` of the plugin),
  `finish_reason` length / content_filter refused, 401 / 402 / 429 / `insufficient_quota` explained; `models()` = `GET /models`, cached an hour
  for the model field's datalist) with `ChatCompletionsSettings` as its page (`<id>.api_key` encrypted, `<id>.model`
  typed, empty = the first of `suggestedModels()`): `plugins/DeepSeek` (api.deepseek.com) and `plugins/Kimi`
  (api.moonshot.ai/v1; `GAIACP_KIMI_BASE_URL` for Moonshot's China platform) and `plugins/OpenAi` (ChatGPT,
  api.openai.com/v1, `max_completion_tokens` up to 16000 since reasoning counts, `writesText()` keeps only chat models
  in the list) and `plugins/Ollama` (`ollama.base_url` on its page via `connectionFields()`, default
  http://localhost:11434/v1; configured without a key, and no Authorization header is sent without one; `timeout()` 600 s)
- Failed jobs: `App\Models\FailedJob` over Laravel's `failed_jobs`, Settings → Failed jobs (`Pages\FailedJobs`, super
  admins: View, Retry = `queue:retry`, Delete = `queue:forget`, Retry all, Delete all = `queue:flush`). The scheduler
  runs `queue:work --stop-when-empty --max-time=50` every minute unless the queue is `sync`, so jobs need no worker
- `plugins/Vies/`: `Vies` (the EU's VIES REST API `check-vat-number`, JSON, with your country and VAT number as the
  requester for a consultation number; `normalize()` drops the prefix, Greece is `EL`; `MEMBER_STATES`; logs `vies.checked` /
  `vies.failed`), every answer kept in `vies_checks` (`ViesCheck`, by VIES code + number, not by client). `CheckAction`
  is the button next to the VAT number on the client form (fills an empty company name). Reverse charge
  (`gaiacp.vies.reverse_charge`, off by default; Settings → VIES VAT check): no tax for a client in another EU country
  than yours (`vies.home_country`, else Region) whose current VAT number's latest check is valid and younger than
  `vies.valid_days`, with `vies.reverse_charge_note` (else the built-in text in the client's language) as the notice's note
- `plugins/Aade/`: `AadeRegistry` (ΑΑΔΕ RgWsPublic2 lookup by ΑΦΜ: SOAP 1.2 + WS-Security built by hand, no ext-soap; special
  access codes from Settings → ΑΑΔΕ (`AadeSettings`), `aade.password` encrypted, `aade.enabled` off = no button; logs `aade.lookup` / `aade.failed`), `Afm` (check digit).
  `LookupAction` is the lookup button on the ΑΦΜ field of the client form (`clientVatNumberActions()`), which only fills fields
  (saved with Save). Request shape follows a working WooCommerce invoicing plugin
- `app/Support/Health/`: `SystemHealth` (the checks behind `Pages/SystemHealth`, Settings → System health, and
  `gaiacp:health`), `SchedulerMonitor` (last cron `schedule:run` and last run of each daily job, in the cache, fed by
  `app/Listeners/RecordCommandRuns`). A new daily command goes in `SchedulerMonitor::DAILY_COMMANDS` too. Cron
  stopped (`stopped()`: no `schedule:run` for `STOPPED_AFTER_MINUTES`, counted from when the heartbeat was first found
  missing after an emptied cache) shows in the dashboard's Needs attention and rings the super admins' bell once
  (`scheduler.stopped`, checked on admin page loads by `Http/Middleware/AlertWhenCronStops`), then `scheduler.resumed`
  on the next run
- `plugins/WhmcsImport/`: `WhmcsImport` (WHMCS database → servers, products + `tblpricing`, clients with ΑΦΜ / Δ.Ο.Υ. / profession from
  custom fields (any other client custom field becomes a `ClientField`, values in `clients.custom_fields`), hosting, domains, unpaid invoices as payment notices (website maintenance hours become one-off Maintenance services), billable items as Maintenance services (recurring ones renew, uninvoiced one-offs auto-terminate); one transaction, a dry run rolls it back),
  `WhmcsCrypt` (WHMCS's password encryption, keyed by `cc_encryption_hash`), `WhmcsApi` (WHMCS's DecryptPassword for the rest, optional), `WhmcsConnection` (the read-only `whmcs`
  connection, `WHMCS_DB_*`, in the plugin's `config.php`). Imported rows are remembered in `whmcs_links` (entity + WHMCS id → local id) so a re-run skips
  them; records already here (same email, hostname, product name, domain) are linked, never updated. `WhmcsImport::PARTS`
  can be left out (`run(parts:)`); a left-out part only links what is already here, in memory. Run by
  `gaiacp:import-whmcs` (`ImportWhmcs`) and `WhmcsImportPage` (Settings → System → Import from WHMCS)
- Debugging (Settings → Logs): `app/Support/ModuleLogger.php` is a Guzzle middleware every API client adds
  (`->withMiddleware(ModuleLogger::middleware(module, action, secrets))`): while Settings → Logging → Module log is on
  (`gaiacp.debug.module_log`, off by default) it writes each request / response / error to `module_logs`, masking the
  secrets passed in, auth headers and password-like fields. A new API client adds it too. `App\Listeners\LogSentEmail`
  (MessageSent) keeps every sent email in `email_logs`; `ClientMailer` / `SendRenewalReminders` log failed sends.
  `gaiacp:prune-logs` (daily) deletes rows older than the retention days, `activity_logs` and `notifications` too when
  `debug.activity_log_days` / `debug.notification_days` are set (null = kept), and expired database cache rows. `app/Support/LogReader.php` reads
  `storage/logs/*.log` for the Error log page (`Pages/ErrorLog`)
- `app/Filament/KeyboardShortcuts.php` + `resources/views/filament/keyboard-shortcuts.blade.php` (panel `BODY_END` hook):
  "g" / "n" + letter go to a page / open a new record, "/" and Ctrl/⌘+K search, "?" (or user menu) lists them;
  ignored while typing. A new main page gets a sequence there
- `app/Filament/Clusters/Settings/`: Settings (`/admin/settings`, sidebar and user menu) is a Filament cluster.
  `SettingsCluster` shows a card per page grouped by `GROUPS` (General, Billing, Products & domains, Client area, Support, Email & notifications, Integrations, Security, System, Logs; a plugin page sets the one that fits) with a search box; every page in it shows
  the same groups as a left sub-navigation (a card in the shared theme layout). `SettingsFormPage` puts "Settings / <group>"
  over the title, its `settingsDescription()` under it and a sticky Save bar (an unsaved-changes note and Cancel =
  `discard()`, by Alpine; `resources/views/filament/settings/styles.blade.php`). Every other form's buttons float the
  same way once they scroll out of view: `BasePage::stickyFormActions()` in `AppServiceProvider` (resource create / edit
  pages, both profile pages), `->sticky()` on custom pages' footer actions (`EditEmailTemplate`), all on the right with
  Save rightmost (`BasePage::alignFormActionsEnd()`; both `EditProfile`s override Filament's left-pinned profile), styled like the Save
  bar by `resources/views/filament/sticky-actions.blade.php` (HEAD_END in both panels). A page joins with `$cluster`, a `$navigationGroup`, a `$navigationSort`
  inside its group's range and `SettingsCard::settingsDescription()` for its card. A payment plugin's settings page uses
  `PaymentMethodPage`: listed on Settings → Payment methods (`PaymentMethodsSettings`, every plugin with payment modules)
  and nested under it in the sub-navigation, with no card of its own. `PluginSwitch` puts an On switch (= the plugin's
  Enable / Disable, super admins only) above a plugin's settings form; such a page stays open while the plugin is off. Old `/admin/products` etc. URLs
  redirect (`routes/web.php`)
- Messages to clients: `ClientMessage` (`texts` per locale falling back to the default one, `filters`, `clients()` /
  `emailClients()`, `scopeShowing()`), `ClientMessageRecipient` (one row per client fixed by Send),
  `App\Actions\SendClientMessages` (`queue()`, `sendDue()` from `gaiacp:send-client-messages` every minute, no queue
  worker), `ClientMessageMail` (client email layout; `ClientMessageKind::Info` appends the signed `unsubscribe` link,
  `UnsubscribeController` asks before turning `clients.informational_emails` off). Home lists the matching ones
  (`Client\Pages\Dashboard::announcements()`). Standalone client pages use `client-area/page.blade.php`
- Leads (Clients → Leads, `AdminArea::Sales`): `leads` (`Lead`: contact details, `source` from Settings → Sales →
  Lead sources (`sales.lead_sources`, `Leads::sources()` falls back to translated defaults), `LeadStatus` New /
  Contacted / Quoted / Won / Lost with `lost_reason`, `assigned_to`, `next_contact_on`, `client_id` once won) and
  `lead_notes` (`NotesRelationManager`; a note on a New lead makes it Contacted). `App\Actions\Leads`: `create()`,
  `addNote()`, `markLost()`, `reopen()`, `convert()` (a new client, or the client who already has the email via
  `existingClient()`; optional client area invitation), `followUps()` from `gaiacp:lead-follow-ups` (daily 08:00):
  one `lead.follow_up` per contact day (`leads.follow_up_sent_on`), for the bell of the assigned admin, else everyone
  in Sales (`AdminAlerts`). `NeedsAttention` lists the leads to contact today; Reports → Lead sources counts them by
  source. `lead.created` / `lead.converted` / `lead.lost` are `GaiaEvent`s with a `lead` block
- Quotes (Billing → Quotes, `AdminArea::Sales`): `quotes` (`Quote`, `QT-2026-0001`, `QuoteStatus` Draft / Sent /
  Accepted / Declined / Expired) for a lead or a client, `items` in the New order item format
  (`OrderForm::itemsRepeater(forQuote: true)`, prices in cents, so they go to `PlaceOrder` as they are) and `extras`
  (one-off lines). `App\Actions\Quotes`: `send()` (Quote email with the link, the lead becomes Quoted), `accept()`
  (claims the row first so two clicks place one order; the lead is converted with the invitation, then `PlaceOrder`
  with each extra as an other service of `BillingCycle::OneTime`), `decline()`, `duplicate()`, `expire()` from `gaiacp:expire-quotes`
  (daily 00:20). The page `/quote/{token}` (`Client\QuoteController`, no login, drafts 404) shows it in its
  language with Accept (terms of service when required) / Decline and Print. `quote.accepted` / `quote.declined`
  ring Sales (`AdminAlerts`); `quote.sent` / `accepted` / `declined` / `expired` are `GaiaEvent`s with a `quote` block.
  Settings → Sales: `sales.quote_valid_days`, `sales.quote_footer`. Client area → Billing → Quotes
  (`Client\Resources\Quotes\QuoteResource`, in the menu once the client has a sent one, also those written for the
  lead that became them) accepts or declines in the table; `quote.sent` rings the client's bell (`ClientAlerts`).
  REST API: `leads` and `quotes` areas (`Api\LeadController`, `Api\QuoteController`); a `lead.created` with no admin
  behind it (the API) rings Sales
- Coupons (Billing → Coupons, `AdminArea::Sales`, `CouponResource`): `coupons` (`Coupon`, code stored upper case,
  `CouponType` percent / fixed in base-currency cents, `recurring`, `product_ids` / `tlds` (both empty = everything),
  `starts_on` / `ends_on`, `max_uses`, `once_per_client`, `new_clients_only`, `active`). `App\Support\Coupons`:
  `problem()` / `check()` (a use = an order with `orders.coupon_id` not cancelled), `apply()` lowers each covered
  item's `first_payment_cents` by the discount on its price (a domain's: its registration price; never the setup fee)
  and with `recurring` its `price_cents` too, setting `coupon_id` on it. `PlaceOrder::handle(coupon:)` locks the coupon,
  checks it again, stores `orders.coupon_id` / `discount_cents` and `services.coupon_id`, and records a notice left at
  0 as paid. The client Cart takes the code (session `client_order.coupon`, also from `?coupon=` on Order, the public
  order page and Cart), New order has a Coupon select, `POST /api/v1/orders` a `coupon`. `GenerateInvoices::describe()`
  adds "Coupon CODE" to the line (first payment, or every one when recurring)
- Sale prices (`App\Models\Concerns\HasSale`: `sale_starts_on` / `sale_ends_on`, both days included, `onSale()`): a
  product's `prices.{cycle}.sale_cents` (`Product::salePriceFor()` / `orderPriceFor()`), a TLD's `sale_prices`
  (`{register_1y_cents: cents}`, `Tld::SALE_KINDS` only; `salePriceCents()` / `orderPriceCents()`); other currencies
  at their rate, never at or above the price. `ClientOrder::items()` puts the sale price in `first_payment_cents` (setup
  fee added) and marks the item `sale_cents`; `price_cents` stays the normal one, so renewals are at it. `Coupon::covers()`
  skips an item with `sale_cents` unless `coupons.on_sale`, and then `Coupons::apply()` lowers the sale price. Admin
  New order pre-fills the sale price; the Sale badge is `filament.client.product-badges` (`sale`), the struck price
  `.gcp-was` in `filament.themes.client`
- `app/Support/Settings.php` + `app/Filament/Clusters/Settings/Pages/`: admin-editable settings, stored
  in `settings` and loaded over `config('gaiacp.*')` at boot, so code keeps reading `config()`. Keys in
  `Settings::ENCRYPTED` (FTP and zip passwords) are stored encrypted and never sent back to the form (blank keeps them). New editable setting:
  add a default in `config/gaiacp.php` (a plugin's in its `config.php`), its key to `Settings::KEYS` (a plugin's to its
  `settingKeys()`; `Settings::keys()` has both) and to `keys()` of the `SettingsFormPage` that edits
  it (one page per area, each with its own Save; a test checks every key is on exactly one page), and a field there
- `app/Enums/MailTemplate.php` + `app/Support/MailTemplates.php` + `app/Mail/` (`ClientMail`, `RenewalReminderMail`,
  `ClientMailer`): every client email, named as in WHMCS, with built-in subject/body (Markdown/HTML) and WHMCS-style
  `{$placeholders}`, plus an on/off switch (`enabledByDefault()`; only the reminders are on). Edits are rows in
  `email_templates` (null = built-in); other languages' text in `email_template_translations` (none = that language's built-in text from `lang/<code>/mail_templates.php`, Greek included, else the default language's). Settings → Email templates lists them (`Pages/EmailTemplates`), each opens
  `Pages/EditEmailTemplate` with a preview. Every one goes through `resources/views/mail/template.blade.php`, the layout
  from Settings → Mail (`MailLayout`: header / footer text with placeholders) inside `<x-client-mail>` (the embedded company logo or name,
  `MailLayout::companyLogo()`). The admins' system emails (`resources/views/mail/*` except `template`) use `<x-admin-mail>` instead, with the GaiaCP logo (`MailLayout::gaiacpLogo()`); `Settings::applyMailer()`
  points Laravel's mailer at the SMTP server from that page (`Settings::ENCRYPTED` keys are stored encrypted). Actions send through `ClientMailer` after they succeed; a failed send logs
  `email.failed` and never undoes the action. New email: a case with its placeholders, sample values and default text,
  then a `ClientMailer` call where it happens. The `invoice_*` templates are payment notices (proformas, not myDATA); the invoices work sends them
- `database/seeders/DatabaseSeeder.php`: local demo data only
- Releases: `VERSION` (semver) read by `App\Support\Version` (signed-in footer, System health, `artisan about`);
  `CHANGELOG.md` keeps a `## [<version>] - <date>` section per release (a test checks the current one). A `v<version>` or `<version>`
  tag (pushed, or a release published on GitHub; or Run workflow with a tag) runs `.github/workflows/release.yml`: tests, then `bin/build-release.sh` (git archive minus `export-ignore` in
  `.gitattributes`, `composer install --no-dev`) and attaches the zip + `.sha256` to the tag's release (creating it if needed). Staging still deploys `main`
- Installer: `app/Install/Installer.php` (requirements, `.env` writing, database, migrate, company, first admin, lock),
  used by `InstallController` (`/install`, plain Blade in `resources/views/install/`, Form Requests `Install*Request`) and
  `gaiacp:install`. `App\Http\Middleware\PrepareInstaller` runs first in the `web` group and the panel: while not
  installed (no `storage/app/private/installed` and no `.env`, or one with `GAIACP_INSTALLER=open`) it writes `.env` + key,
  keeps the installer's session in files and sends every page to `/install`; installed, `/install` is a 404
- Setup guide (Settings → General → Setup guide, `/admin/settings/setup`, super admins): `App\Support\SetupGuide` lists
  the steps after `/install` (business, region, mail, payment methods, tax, server, domain prices, product, client area
  and terms, backups, cron, two-factor), each opening an existing page. A step is done when what it sets up is there
  (`check`), or when the admin marks it done; any can be skipped. Marks and "hidden from the dashboard" live in the
  `setup_guide` row of `settings` (not an editable setting); `SetupGuideCard` sits at the top of the dashboard until
  every step is done or skipped or it is hidden. Installs that already had clients start hidden (migration)
- Welcome tour (both panels): `App\Support\WelcomeTour` gives the cards (only those whose page the user can open; the
  admin's last one opens the setup guide while a super admin has steps left), `filament.welcome-tour` (`BODY_END` hook)
  shows them beside what each points at (`target`: a selector or `nav:` / `navgroup:` + a menu item's URL), dimming the
  rest; on a phone it opens the side menu for menu targets, and an absent target puts the card in the middle. It opens by itself once per user and panel (`users.admin_tour_seen_at` /
  `client_tour_seen_at`, set when it opens; never while an admin looks as the client) and from the user menu → Tour.
  Settings → Client area → Welcome tour (`client_area.welcome_tour`) turns off the client one. Users from before it
  count as having seen it (migration)
- Document root on GaiaCP's own folder (shared hosting, subfolders): the root `.htaccess` answers 404 for anything that
  exists outside `public/` (and any dotfile) and rewrites the rest into `public/`; `bootstrap/base-path.php` (first
  thing in `public/index.php`) then sets `SCRIPT_NAME` to the folder above `public/`, so the base path and every URL
  lack `/public`. `SystemHealth::privateFiles()` fetches `/.env` and `/composer.json` through the site (Error when
  either is served, Info when the site can't reach itself); the installer's requirements include it, so it stops
- Updates: `gaiacp:check-for-update` (daily 05:50, off with `GAIACP_UPDATE_CHECK=false`) logs `gaiacp.update_available` once per
  newer release (bell for super admins, a line in the daily summary). `app/Updates/` `GitHubReleases` (latest release of `gaiacp.updates.repository`, token for a private repo) and
  `Updater` (RunBackup, download + SHA-256, `down`, swap the release's top-level entries into place with the old ones in
  `storage/app/private/updates/<version>/previous`, moving back into `database/`, `plugins/` and `public/` what the release
  lacks, then migrate; any failure after the swap puts the old code back). Never for a Git checkout. Used by
  `Pages/Updates` (Settings → System → Updates) and `gaiacp:update`
