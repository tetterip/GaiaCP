# Features

Everything GaiaCP does today, what it leaves out on purpose, and the order it was built in. The
[README](../README.md) has the short version; the [admin guide](admin-guide/README.md) explains how to use each part.

## Scope

**In scope**

- Clients with the details a domain registration needs (name, company, email, phone, structured
  address, country) plus VAT number, tax office (Δ.Ο.Υ.), profession and email preferences
- Client fields of your own (Settings → Client fields, as WHMCS's client custom fields): text, several lines, a list
  of choices, yes / no or a date, with a label per language; each is required or not, hidden from clients, shown to
  them or filled in by them at sign up and in their profile (saved at once, no approval), and can show on payment
  notices under the client's details. Deleting one keeps the clients' values
- A product catalog (e.g. "Hosting Basic", "Maintenance") that pre-fills new services, with a price and setup fee
  per billing cycle (monthly to triennially), Hidden / Retired switches and a "Show in client area" switch (each
  service keeps its own copy); clients see their Maintenance services read-only but can't order Maintenance
- Product groups (Settings → Product groups, as WHMCS's product groups): Shared hosting, VPS and so on, named per
  language with an icon and a link of their own (`/order/<group>`, `/cart/<group>`; a hidden group only by its
  link). The Order page, the public order page, the public home page and its side menu show products by group; each
  product can have an icon, a colour of its own (name, buttons, marks), a Popular badge, tags (Settings → Product tags: New, SSD…, named per language with a colour;
  shown to clients as badges or kept for the admin's filters on products, services and messages) and its values for
  the group's compare table (Disk space, Websites…, Yes / No as marks), which also lists each billing cycle's price and has a Choose button per package.
  Existing installs got one group per type; the WHMCS import brings WHMCS's groups
- Product options (Settings → Product options, as WHMCS's configurable options): groups of options attached to
  products (a list, buttons, yes / no or a quantity, e.g. location or extra IPs), each choice with a price per cycle
  (or the monthly one times the months) and a setup fee; New order and the service form offer them, the service's
  price includes them and its payment notice lines name them. Clients pick the ones not hidden from them on the Order
  page and the public cart, and see them on their service. Only billing so far: nothing changes on the server
- Add-ons (as WHMCS's addons): a product marked Add-on, for some or any products, is added from a service's page
  (Add-ons tab) and becomes a service of its own on the same cycle and due date, so both are on one payment notice;
  on a pending order it joins the order, on a running service it is first owed on that service's next due date (no
  proration). It is suspended, unsuspended, set to end, ended and moved to another client with its service. Never
  ordered on its own. Clients add them to an active service from its page (Add an add-on, while client orders are on):
  a payment notice due at once for the days until the service's next due date plus the setup fee, and the add-on is
  active once it is paid. They can also tick them under the product on the Order page and the public cart: they join
  the same order and first payment notice (setup fee included) on the product's cycle and are set up with it.
  Add-ons are billing only: nothing changes on the server
- Upgrade / downgrade (service page → Price): another product of the same type; the new package is set on the server
  at once and the new price is owed from the next due date (a notice already issued for it is updated), or, per
  change, "charge the difference now" bills the days left until then at the new price (a payment notice due today; on a
  downgrade the difference goes into client credit when it is on). Clients ask for it from their
  service's page (another package clients can order); you approve or decline under Services → Upgrade requests
- Admin orders: one order per client with several items (domain, hosting, maintenance); completing it registers
  the domain, creates the hosting account and activates the rest
- Services: hosting accounts on **cPanel/WHM**, **Plesk**, **HestiaCP**, **DirectAdmin**, **CyberPanel** and **Enhance** servers, VPS on **Proxmox VE** (cloned from a template, IP pool, start / stop for admin and client), **domains** at one or more registrars (easy.gr, Enom),
  and plain recurring services such as yearly **maintenance**. A hosting account shows its disk space and this month's
  bandwidth against its limits, read from the panel, to you and (unless switched off) to the client. An account that
  reaches 85% (Settings → Automation → Usage warning) of either gets a Usage Warning email suggesting a bigger package,
  and the bell rings, once a month for each
- Billing cycles and due dates per service, with editable email templates
- Payment notices (proformas, WHMCS-style "invoices" that are not legal invoices): one per client and due date,
  created and emailed ahead of it, with reminders and overdue notices; paid when its payments are recorded
- Invoices (Settings → Invoices, off by default): a paid payment notice gets an invoice with its own number (format
  with `{YEAR}` `{MONTH}` `{DAY}` `{NUMBER}`, no gaps, next number only forward), a copy never changed or deleted;
  emailed to the client as a PDF (Invoice Issued) and listed in the client area; credit notes (by hand for chosen
  lines, or on its own when a payment is reversed) in a series of their own; CSV export for the accountant; national
  e-invoicing (myDATA, Peppol…) as plugins is planned
- Price changes with notice (Change price: on a service, the Services list, a product or a TLD): the current price
  plus a percentage, a set amount, or the product's / TLD's current price, for renewals due from a date at least
  Settings → Automation → Price change notice ahead (30 days by default). Each client gets one Price Change Notice
  email listing their services; notices already issued keep the old price, and the client area shows the coming one
- Tax (VAT) on payment notices: on/off, prices with or without tax, rules per country, tax-exempt clients, and the
  EU reverse charge for businesses whose VAT number VIES found valid (VIES VAT check plugin)
- Client credit (Settings → Client credit, off by default): clients with an active service add funds through an
  "Add funds" payment notice (no tax; paid by card or bank transfer like any other; 10–300 per deposit, at most 600 in
  all by default) and the balance pays their payment notices, a whole line (one service's cycle) at a time, by itself
  in the daily run and on new orders, or with "Pay with credit". Admins add or take off credit on the client page,
  with a reason the client sees in their credit history
- Payments recorded by hand: "mark as paid" moves the due date forward one cycle. Card payments through Stripe (a
  bundled plugin): "Pay by card" on the client's unpaid payment notices opens Stripe Checkout, and the payment is
  recorded the same way once Stripe confirms it. PayPal the same way (a bundled plugin, off by default): "Pay with
  PayPal" opens PayPal's page, and GaiaCP takes the money only if the notice still asks for that amount
- Daily automation: payment notices and their reminders, domain renewal notices, optional auto-suspend after a grace period,
  and a daily summary email to the admins (new and overdue payment notices, domains whose sync changed or failed;
  on/off under Settings → Automation, and per admin with Receive system emails under Settings → Admin users)
- Panel actions: create, suspend, unsuspend, terminate, change package
- Registrar actions: register, renew, transfer, nameservers, expiry-date sync; contact details, private nameservers
  (ns1.the-domain) and the registrar lock where the registrar offers them (easy.gr: contacts and private nameservers;
  Enom: all three), for admins and, each behind a switch, clients
- Backups: a zipped (optionally password-protected) SQL dump of the database, daily or weekly, copied to FTP/FTPS
  and/or emailed
- Import from WHMCS (Settings → System, or `gaiacp:import-whmcs`): servers, products and prices, clients (ΑΦΜ, Δ.Ο.Υ.,
  profession, other custom fields as client fields), hosting accounts with their passwords, domains and unpaid invoices, with a dry run first; safe to re-run
- System health (Settings → System): PHP version and extensions, configuration, database, storage, cron and the daily jobs,
  mail, server and registrar connections
- Debugging (Settings → Logs): a WHMCS-style module log of every request to cPanel/WHM, Plesk, HestiaCP, DirectAdmin, Proxmox VE, easy.gr, Enom and ΑΑΔΕ
  with the reply (passwords, tokens and EPP codes masked; off until switched on), an email log of every email sent or
  failed, and a viewer for Laravel's error log
- Reports (sidebar → Reports): annual income (month by month against the year before), monthly transactions, income
  by product, an income forecast from the services' renewals, revenue per server, and WHMCS-style Domains and Services
  reports (pick the fields, filter, date ranges, CSV export). Every report exports to CSV
- Messages to clients (Clients → Messages to clients): one text per language, emailed to the active clients that match
  the filters (servers, products, domain endings, service types, language) and/or shown at the top of their client area
  Home between two dates; a live count, Send test to me, sent a few a minute by the cron (Settings → Automation →
  Messages per minute) with a Recipients list. Informational ones end with an unsubscribe link (the client's
  "Informational emails" switch); ones about their services reach everyone matched
- Sales opportunities (Reports → Sales opportunities): domains to transfer to you (tracked domains, hosting whose domain
  is registered elsewhere), domains without hosting, and expired domains still within their TLD's grace days; each row
  has an Email button that opens its template (Domain Transfer Offer, Hosting Offer, Expired Domain Win-back) to edit
  before sending, and shows when it was last sent
- Client page "Payment record": income from the client, their average payment delay over 12 months (rated Pays on time
  up to 3 days, Watch up to 14, Pays late beyond), late payments and suspensions
- Plugins (Settings → System → Plugins): cPanel / WHM, Plesk, HestiaCP, DirectAdmin, CyberPanel, Enhance, Proxmox VE (all three off by default), easy.gr, Enom, Email Notifications, ΑΑΔΕ, VIES VAT check (EU VAT numbers checked from the client form, and the reverse
  charge: no VAT for EU businesses in another EU country), the WHMCS import, Bank transfer (the first
  payment method plugin: its bank details are printed on payment notices), Stripe (card payments in the client area,
Settings → Stripe for the keys and the webhook), PayPal (off by default, Settings → PayPal), Google sign-in ("Continue with Google"
  in the client area, with your own Google OAuth client), Google reCAPTCHA and Cloudflare Turnstile (the captcha
  Settings → General puts on the login, sign up and forgot password forms), Uptime Kuma, UptimeRobot, HetrixTools and Better Stack (server monitoring, below), IP blacklist check and Site checks (below) and
  the admin panel themes Gaia and Flat are bundled plugins,
  each on by default (except easy.gr, ΑΑΔΕ and VIES VAT check, off in a new install unless `GAIACP_PLUGINS_START_ENABLED` names them)
  and switched off when not used (their servers, accounts and settings stay); bundled plugins
  can't be uninstalled. Each has its own version and changelog, shown on that page with when it was installed or
  updated; a plugin that needs a newer GaiaCP stays off until GaiaCP is updated. Other plugins are installed with
  Upload plugin on that page (a zip, super admins, with your password; checked before it goes live, its migrations
  run, it starts disabled), updated by uploading a newer zip and uninstalled once disabled (its tables and settings
  kept unless you ask). A folder copied into `plugins/` is found too. An uploaded plugin runs as GaiaCP itself, with
  access to everything, so only upload plugins you trust; `GAIACP_PLUGIN_UPLOADS=false` turns uploads off (System
  health shows whether they are on). How to write one: [a tutorial](plugin-tutorial.md) and the reference, [writing-plugins.md](writing-plugins.md). Settings → System → Plugin catalogue lists the plugins on offer at plugins.gaiacp.digital, signed so that only GaiaCP's key is trusted, to install or update from the panel ([plugin-catalogue.md](plugin-catalogue.md))
- Webhooks (a plugin, off until switched on under Settings → Webhooks, super admins): GaiaCP's events (orders, client
  sign ups, payment notices, payments, services, domains, tickets, servers) sent as a JSON POST to the URLs you add,
  each with the events it wants, for your own scripts, Zapier, Make or n8n. Each request is signed with the
  endpoint's secret (shown once; `X-GaiaCP-Signature: sha256=` HMAC of `{timestamp}.{body}`), sent within a minute
  by the scheduler and retried after 1, 5 and 30 minutes, 2 and 12 hours. A delivery history shows what was sent and
  the answer, with Resend; Test sends a sample. An endpoint that keeps failing rings the super admins' bell once and
  is switched off after 50 failed tries in a row. URLs on local or private network addresses (and cloud metadata)
  are refused unless `GAIACP_WEBHOOKS_ALLOW_PRIVATE=true`, e.g. for n8n on the same server. Plugins can listen to the same events (`Plugin::events()`)
- REST API at `/api/v1` (Settings → API keys, super admins): a key for each script or site, sent as
  `Authorization: Bearer gcp_…` (or `X-API-Key`), with its permissions per area (clients, services, orders, payment
  notices, payments, products × read / create / update / delete) and the IPs it may call from. A key is shown once
  (only its hash is kept), its last use is noted and it can be revoked at once. Calls go through the same actions as
  the panel (place / complete / cancel an order, record or reverse a payment, pay or email a payment notice, suspend /
  unsuspend / terminate a service), so they send the same emails, are in the activity log under the key's name and
  raise the same webhooks. 120 calls a minute per key (`GAIACP_API_PER_MINUTE`). Settings → API keys → Documentation
  lists every call, with `openapi.json` for Postman or a code generator; how to use it, with examples:
  [using-the-api.md](using-the-api.md)
- Slack and Discord (plugins, off until switched on under Settings → Slack / Discord, super admins): the events you
  pick (by default new orders, payments, tickets, client replies, servers down and up, failed panel calls) posted to a
  channel through its webhook (stored encrypted), one short message each with a link to its page in the admin area,
  in the app's language. Sent within a minute, retried after 1, 5 and 30 minutes; Send a test message checks it
- Telegram bot (plugin, off until switched on under Settings → Telegram, super admins): the same events as Slack /
  Discord sent to one Telegram chat (a person or a group of admins), and, when Answer commands is on, read-only
  answers in that chat only to /owed (who owes, from overdue payment notices), /today (payments, orders and tickets),
  /tickets (waiting for an answer) and /servers (status and 24-hour uptime). Find chat ID reads it from the bot
- AI assistant (Settings → AI assistant, super admins; the Claude plugin, off until switched on, with your own
  Anthropic API key under Settings → Claude): an AI menu on the texts admins write (ticket replies, canned replies,
  email templates, messages to clients, knowledge base, announcements, product texts, quotes, sales emails) that
  improves a text (spelling, friendlier, clearer, shorter, more formal) or translates it (into the client's or a
  panel language, or one language's text from another's). Suggestions only: shown in a window to edit, used only when
  the admin presses Use this text. Only that field's text is sent; each feature has its own switch, a monthly token
  limit covers all admins, and each answer's tokens are kept (not the text). It also writes a product's description
  from its details (no prices), the client area's server problem notice from what the monitors say (public server
  names only), and, off until switched on, suggests free domain names close to a taken one in the client area's
  domain search (only the name sent, 5 presses an hour per client or address). Reports → Ask in plain words answers
  questions such as "who owes more than €100?" from the data, read-only: the AI service only picks one of GaiaCP's
  ready-made questions and its dates or amounts, and never sees the answer; System → Help answers "how do I…" from
  the admin guide's closest sections. On a ticket it drafts a reply (from the conversation, the client's services and
  the closest knowledge base articles), summarises it with a suggested priority, department and mood (kept under the
  subject in the list until the client writes again), and turns an answered one into a draft knowledge base article;
  the conversation goes without notes, files or the client's name, company, emails and phone numbers. A payment
  notice's Personal reminder rewrites its reminder email for how the client pays, and a client's Suggestions list what
  they might buy, signs they may leave and what to look after (facts only: services, prices, payment history, ticket
  subjects; never who they are). Settings → Languages → Translate with AI fills a language's missing texts in batches
  (only the texts sent; a translation that loses a placeholder is left out). ChatGPT (OpenAI), DeepSeek and Kimi
  (Moonshot AI) are plugins too (off until switched on, own key and model, Test connection lists the key's models), and so
  is Ollama (an open model on the admin's own server or computer, no key, nothing billed); Settings → AI
  assistant picks one when several are on
- The admin panel as an app (PWA): add it to a phone's home screen or install it from the browser, and turn on push
  notifications per device under Profile → Push notifications: the bell's notifications arrive on the phone or computer
  even when GaiaCP is closed (Chrome, Edge, Firefox, Safari; on an iPhone only once it is on the home screen)
- Settings → Failed jobs: background jobs that failed every try (Laravel's `failed_jobs`), with their error, Retry and
  Delete. Queued jobs run from the scheduler (`queue:work --stop-when-empty` every minute), so no worker is needed
- Server monitoring: a server links to its monitor in an uptime monitoring service (a plugin: Uptime Kuma, read
  from its `/metrics` page with an API key, or UptimeRobot, read with a Read-Only API key, or HetrixTools or Better Stack Uptime, each read with an API token, on the Free plan too, every 5 minutes). The Servers list shows Up / Down / Pending /
  Maintenance with an uptime bar (a dot per hour for 24 hours, or per day for 30 days, and the uptime
  percentage), the dashboard's Servers card a dot and the 24-hour bar per server, and super admins get a bell notification when a server
  goes down and when it is back up (with how long it was down). Other services can be
  added as plugins. While a server is down or in maintenance, its clients see a notice at the top of the client area
  naming their services on it (Settings → Client area → Server problems, on by default, with your own message)
- IP blacklist check (a plugin, Settings → IP blacklist check): every hour each active server's IP (its IP address
  field, else its hostname's) is looked up in DNS blacklists (Spamhaus ZEN, SpamCop, Barracuda, PSBL by default);
  super admins get a bell notification when one is listed and when it is removed again. A list that refuses to
  answer (Spamhaus through a public DNS resolver) counts as not checked, never as listed
- Site checks (a plugin, Services → Site checks): every day at 05:00 each active hosting account's domain is checked:
  does it still point to its server (its IP address fields or hostname; behind Cloudflare is noted, not compared), and
  does it answer HTTPS with a trusted certificate for its name that doesn't expire within Settings → Site checks →
  Warn before expiry (14 days by default). A new problem goes to the bell of the admins who open services, once;
  Check now per site and Check all now on the page
- Support tickets (Support → Tickets): clients open tickets from the client area (department, the service it is
  about, priority, attached files) and answer them there; admins reply (starting from a canned reply), add internal
  notes the client never sees, assign, move and close them, and open one for a client. The client is emailed each
  answer; the admins get an email and the bell for new tickets and replies. A ticket waiting on the client closes by
  itself after Settings → Support → Close tickets after (7 days). Departments, canned replies, file size and types are
  under Settings → Support; the dashboard's Support card lists the tickets waiting for an answer. With the Email to
  ticket plugin a support mailbox is read over IMAP every minute: answers to ticket emails join their ticket and new
  emails open one (from someone who isn't a client, a ticket without a client, or ignored). A mail provider
  (Mailgun, SendGrid, Postmark) can post the emails to a secret webhook address instead
- Leads (Clients → Leads): prospective clients kept apart from the clients until they buy, with where they came from
  (Settings → Sales → Lead sources), what they are looking for, dated notes, who has them and the next contact day:
  that morning the bell reminds the admin it is assigned to (else everyone in the Sales access level) and the
  dashboard lists the leads to contact today. Convert to client makes one a client (or links it to the client who
  already has its email), optionally with a client area invitation; Mark as lost keeps the reason. Reports → Lead
  sources shows how many leads each source brought and how many became clients
- Quotes (main menu, after Orders): an offer to a lead or a client, written like a new order (products, options, domains,
  prices changed for a discount and kept on renewals) plus one-off lines (moving a site), ordered as other services
  billed once (billing cycle One time: Completed once paid), valid until a date (Settings → Sales). Send emails a link to its page, where it is printed, accepted (with the
  terms of service when asked) or declined with a reason, no login needed. Accepting makes a lead a client with the
  client area invitation and places the order with its payment notice; the bell tells Sales. Sent quotes expire after
  their date; Duplicate starts a new one from an old one. Clients also find them under Billing → Quotes in the client
  area, and the REST API adds leads (a contact form on your website) and writes, sends and accepts quotes
- Coupons (Billing → Coupons): a code for a percentage or a fixed amount off each item it covers (chosen products and
  domain endings, or everything), on the first payment only or on renewals too, never on a setup fee; valid between
  two dates, with a usage limit, once per client or for new clients only. Clients enter it in the cart (or follow a
  `?coupon=CODE` link), admins pick it on New order, the REST API takes it with an order. The notice line names it;
  when nothing is left to pay, the order's notice is paid at once
- Sale prices: a product's per cycle and a TLD's for registering and transferring, between two days, for the first
  period of what is ordered then (renewals at the normal price). The order pages, the public home page, domain search
  and Domain prices show the normal price struck through and a Sale badge; it ends by itself. A coupon lowers a sale
  price only with its Also on sale prices switch
- Knowledge base (Support → Knowledge base): help articles in categories, a text per language (Markdown), shown in
  the client area once published, with a search that ignores accents and case, views and "Was this helpful?" counts.
  While a client writes a new ticket, the articles matching its subject show under it. Settings → Support →
  Knowledge base in the client area switches it off
- Downloads (Support → Downloads): files in categories, a title and description per language, for everyone, clients
  who are logged in, or the clients of chosen products; kept private and handed out by GaiaCP after checking who
  asks, with a download count
- WHOIS lookup (Settings → Public area → WHOIS lookup, for visitors and clients): registrar, dates, statuses,
  nameservers and DNSSEC from the registry's RDAP server, else its WHOIS server's text; captcha and a limit per minute
- Domain costs: each TLD can keep what the registrar charges per term (Settings → Domain pricing → Registrar cost).
  The list shows the renewal margin, red when a term sells at a loss; Income forecast takes the month's domain
  renewal costs off the profit, and Reports → Domain profit shows revenue, cost, profit and margin per TLD
- Themes: Gaia (GaiaCP's own look, light and dark: neutral greys, the logo's teal, rounded cards,
  Commissioner and Literata from Bunny Fonts, quiet motion) and Flat (the same layout in Filament's emerald and grey, flat: no shadows or motion). Each admin picks one
  in Profile; Settings → General sets the admin default (Gaia). The client area, the public order page, sign up and
  the maintenance page take the theme chosen under Settings → Client area → Client area theme (Gaia), with the
  company's logo; clients don't choose. Settings → Client area → Brand colour (empty = the theme's) paints the client
  area's buttons, links and highlights in your own colour, light and dark; the admin area keeps its theme's.
  A theme is a plugin with plain CSS, nothing to build
- Currencies (Settings → Currencies): EUR is the base currency, others are added with their format and rate (from
  the European Central Bank, by hand or daily). Each client is billed in one currency; products and TLDs can have their
  own prices in it or follow the rate, and totals and reports show every currency in EUR
- SSL certificates (a service type, sold through the GoGetSSL plugin): GaiaCP makes the key and CSR, orders the
  certificate once the service is set up, shows and emails the client how to validate the domain, finds it issued
  every hour and emails it, issues the next one inside the order before each certificate ends (200 days at most) and
  orders the next period when the service is paid beyond it (see [SSL certificates](reference.md#ssl-certificates)).
  When the domain is the client's hosting account on a cPanel or Plesk server, GaiaCP validates it there (a file on
  cPanel, a DNS record on Plesk) and installs the issued certificate on the account
- English and Greek: each admin picks a panel language and each client an email language, and email templates can be
  written per language (see [Languages](reference.md#languages))

**Out of scope (on purpose)**

- Saved cards and automatic charges, refunds from GaiaCP (refund in Stripe or PayPal)
- Transmitting invoices to a tax authority (myDATA, Peppol…) in the core: that belongs in a plugin per country
- Proration other than an upgrade's "charge the difference now"; credit paying part of a line
- Matching bank statements to payment notices (bank CSV import, RF payment codes): payments are recorded by hand or by a payment plugin

## Build order

1. ✅ Clients, servers, services, manual payments, daily cron with email reminders
2. ✅ cPanel (WHM API 1) actions, auto-suspend, products, full client details
3. ✅ Plesk (XML API) actions with service plans; HestiaCP (API with access keys) as a plugin
4. ✅ Registrars: easy.gr and Enom modules, Email Notifications, registrar accounts, TLD prices (Domain pricing), renewal on payment, domain records,
   register / transfer / nameservers / EPP code, daily expiry sync
5. ✅ Admin orders (domain + hosting + maintenance in one go)
6. ✅ Payment notices (proformas) and tax settings
7. ✅ Database backups to FTP and email
8. ✅ Two-factor authentication for admins (authenticator app + recovery codes, opt-in from the profile); "Forgot password?"
   on the login screen emails a reset link (active admins only, in their panel language; 2FA is still asked)
9. ✅ Import from WHMCS
10. ✅ Admin users (Settings → Admin users)
11. Plugins: ✅ bundled plugins with on/off switches; ✅ each plugin in its own folder (`plugins/`) with its code,
    settings, config and translations; ✅ plugin versions and changelogs; ✅ installing, updating and
    uninstalling other plugins from a zip; ✅ a signed plugin catalogue to find, install and update plugins from the panel
12. Client area: ✅ "Show in client area" switch; ✅ payment methods as plugins (Bank transfer); ✅ client logins
    (invite from the client page, own login / forgot password / profile at the site root); ✅ services, domains, payment
    notices (print / PDF) and payments to view; ✅ profile (email and phone at once, other details approved by you);
    ✅ Devices and sign-ins (signed-in browsers with Log out / Log out other devices, recent sign-ins and wrong
    passwords; the client page's Sign-ins tab shows the same with Log out everywhere);
    ✅ domain self-service (nameservers, EPP code, auto renew; switches under Settings → Client area); ✅ hosting
    self-service (password, one-click cPanel / Plesk login and Quick shortcuts to their pages, Open website, disk and
    bandwidth usage, the server's uptime); ✅ client orders (a cart of products with their domains and
    domains on their own, one order and payment notice, through the same steps as New order); ✅ domain search (the name with
    each TLD sold, free or taken, with its price, from the registrar, RDAP or DNS); ✅ "View in the client area" in client emails, Home
    shortcuts and a Help page for clients (EN / EL); ✅ sign up (off until switched on; the email address is confirmed
    first) and "Continue with Google" (a plugin); ✅ maintenance mode (Settings → Client area: a message or a redirect
    while the client area is closed); ✅ terms of service (a link or text and a version, accepted on client orders and
    sign up, recorded on the order or client); ✅ public order page (`/cart`, off until switched on: visitors choose a
    product and its domain before they sign up or log in, then place the order in the client area); ✅ public area
    (Settings → Public area, each part off until switched on: a home page at the site root with a welcome text, the
    domain search and what is sold, domain search for visitors, a domain prices page, the knowledge base (articles can
    stay for clients only), a contact form whose ticket the visitor follows at a secret link, announcements with an RSS
    feed (each public or for clients only) and a server status page (state and uptime by name, never the hostname or IP);
    side menu and language picker for visitors); ✅ menus (Settings → Client area → Menus: both side menus, for clients and
    visitors, dragged into order, with pages renamed or taken out and products, product groups, links and headings
    added); ✅ search engines (Settings → Client area: each language of a public
    page at its own address with canonical and hreflang links, `/sitemap.xml` and `/robots.txt`; a public page per
    product group with its own text, compare table, questions and structured data, and per product inside it; product
    names and descriptions per language); ✅ cookie banner
    (Settings → Cookies, super admins, off until switched on: a text, a link to the cookie policy and scripts such as
    analytics that run only after "Accept all"; "Cookie settings" at the foot of the page asks again)
