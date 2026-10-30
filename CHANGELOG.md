# Changelog

Every release of GaiaCP, newest first. The version number is in the `VERSION` file; a release is the tag
`v` + that number (see [Releases](docs/releases.md)).

## [Unreleased]

- Invoices (Settings → Billing → Invoices, off by default): each payment notice paid from then on also gets an invoice
  with its own number, the way WHMCS turns a paid proforma into an invoice. Numbers follow a format with `{YEAR}`,
  `{MONTH}`, `{DAY}` and `{NUMBER}` (`INV-{YEAR}-{NUMBER}` by default; with `{YEAR}` each year starts at 1), have no
  gaps, and the next number can only go up. An invoice is a copy of the seller, client, lines and tax when it was
  issued and is never changed or deleted. Billing → Invoices lists them; a paid notice links to its invoice, or has
  Issue invoice when it was paid before.
- Invoices as PDF: the client gets the new Invoice Issued email with the invoice attached, in their language, and
  finds their invoices under Billing → Invoices in the client area and on each paid payment notice. Admins download
  the PDF or send the email again from an invoice's page, and see a client's invoices on the Invoices tab of the
  client's page. Credit notes come next.
- Welcome tour: on the first login, admins and clients get a few cards, each lighting up the menu or part of the top bar it is about (on a phone the side menu opens for them), (clients and orders,
  services, payment notices, support, search and bell, help for admins; services, payment notices, ordering, support,
  profile and help for clients), only for the pages each one can open. The admin's last card opens the setup guide.
  The user menu → Tour shows it again; Settings → Client area → Welcome tour turns off the clients' one. Existing
  users don't get it by themselves.
- Product pages: a product's new Public page section gives it a page inside its group's address
  (`/web-hosting/business`) with a title, a search result description and a text per language, its prices, Order
  button and what it includes, plus Product data for search engines. Without a text it stays out of search results.
  Products also get their name and description in each language (Other languages), shown on the order pages.
- Product group pages: switch on Public page on a product group (Settings → Product groups) and it gets a page of its
  own at the site root (`/web-hosting`) with a title, a text and questions per language, its products with prices and
  Order buttons and the compare table, plus a search result description and structured data (products with their
  prices, FAQ) for search engines. The home page, the visitors' menu and the sitemap link to it.
- Search engines: each language of a public page has its own address (`?lang=el`), and every public page names its
  canonical address and the other languages (hreflang). New `/sitemap.xml` lists the public pages that are switched
  on (knowledge base articles and announcements included) in every language, and `/robots.txt` points to it and keeps
  /admin out, or keeps everything out while Settings → Client area → Search engines is off. Link previews show the
  page's title.
- Product colours: pick a colour for a product (its Order page section) and its name, icon, price, check marks and
  buttons take it on the order cards, in the compare table and on the public home page, with a line along the card's
  top. Product tags and products now pick their colour from ten circles instead of a list.
- Sale prices: a Sale section on each product (a price per cycle) and on each TLD (register and transfer per term),
  from a Sale from to a Sale until day. Orders placed then pay the sale price for their first period; renewals are at
  the normal price. Clients see the normal price struck through and a Sale badge on the order pages, the public home
  page, domain search and Domain prices, and it ends by itself. A coupon skips items on sale unless its new Also on
  sale prices switch is on.
- Product tags (Settings → Product tags): your own labels such as New or SSD, named per language with a colour. One
  shown to clients is a badge after Popular on the order cards, in the compare table and on the public home page (the
  first three of a product); every tag filters the Products and Services lists and Messages to clients.
- Coupons (Billing → Coupons): a code for a percentage or a fixed amount off, on the first payment only or on every
  renewal too, for chosen products and domain endings or for everything, with dates, a usage limit, once per client
  and new clients only. Clients enter it in the cart (or follow a `?coupon=CODE` link), admins pick it on New order,
  and `POST /api/v1/orders` takes a `coupon`. Setup fees are never lowered.
- SSL certificates on your own hosting: when a certificate's domain is the client's hosting account on a cPanel or
  Plesk server here, GaiaCP validates it there (a file in the website on cPanel, a DNS record in the subscription's
  zone on Plesk) instead of emailing the client, and installs the issued certificate on the account. SSL → Install on
  hosting does it by hand; Settings → Domains → Set up certificates on our own hosting turns it off. Not yet tried on
  a real cPanel or Plesk server.
- AI assistant on tickets, each with its own switch under Settings → AI assistant: Draft a reply in a ticket's Reply
  (from the conversation, the client's services and the closest knowledge base articles), Summarise (one line with a
  suggested priority, department and mood; Keep shows it under the subject in the list) and More → Write a knowledge
  base article from an answered ticket (saved unpublished). The client's name, company, emails and phone numbers are
  replaced before anything is sent; notes and files are never sent.
- AI assistant about a client: Personal reminder on an unpaid payment notice (the reminder email rewritten for how the
  client pays, to read and send) and Suggestions on a client's page (what they might buy, signs they may leave, what
  to look after). Only facts are sent (services, prices, payment history, ticket subjects), never who the client is.
- Settings → Languages → Translate with AI (super admins): the AI assistant fills a language's missing texts, 40 at a
  time while the page is open, saved as uploaded translations; ones that lose a placeholder stay in English.
- Ollama plugin (off until switched on): the AI assistant with an open model (Llama, Qwen, Gemma…) on your own server
  or computer, so texts never leave your machines; Settings → Ollama takes its address and model, no key needed.
- The dashboard's Servers card fills its width: the uptime and accounts squares grow with the card (and sit side by
  side on a wide one) instead of stopping at about 60% of it; the same on the client hosting page's uptime. Every
  square is the same whole number of pixels wide.
- The Cloudflare Turnstile check is as wide as the form it is on (login, sign up, forgot password, domain search).

## [0.3.1] - 2026-10-27

- New GaiaCP releases are announced: a daily check rings the bell for super admins once per version (linking to
  Settings → Updates, or saying to deploy on a copy from Git) and the daily summary email mentions it.
  `GAIACP_UPDATE_CHECK=false` turns it off.
- Settings → Updates says when GitHub's rate limit stopped the check (60 requests an hour per server address without
  a token, shared with other sites on it), when to try again, and that `GAIACP_UPDATE_TOKEN` raises it, instead of
  "GitHub answered 403".

## [0.3.0] - 2026-10-26

- First release from the public repository. Releases are signed with a new key (made 2026-10-27): an install that
  trusted the 0.2.0 key refuses this release, so update it once by hand (download the zip, or deploy from Git).
- Settings → AI assistant is its own group: the assistant's page with Claude, ChatGPT, DeepSeek and Kimi beside it
  (no longer under Integrations); Settings → Plugins lists them under AI assistant.
- Eight more languages, machine translated: German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and
  Turkish, for the panel, every plugin, the client emails and the client guide (Settings → Languages marks them).
  An existing install keeps offering only the languages it had: tick the new ones under Settings → Languages
- Contact page (Settings → Public area → Contact page): your details beside the contact form, or alone on the page
  without it: the Settings → Company lines you tick (address, phone, email, website, VAT number), opening hours and a
  text per language, and an Open in maps button. Logged-in clients find it under Support → Contact us. The public area
  settings no longer forget the welcome texts while the home page is switched off
- Reports → Ask in plain words: questions such as "who owes more than €100?" answered from your data, read-only;
  the AI service only reads which of GaiaCP's ready-made questions it is, and never sees the answer. System → Help:
  Ask the AI assistant answers "how do I…" from the admin guide, with links. Each has its switch under Settings → AI
  assistant.
- The web installer looks like the admin panel (the Gaia theme's fonts, teal, logo, rounded cards and buttons), always
  in light.
- AI assistant writes texts from GaiaCP's own details, each with its switch under Settings → AI assistant: a
  product's description from its details (no prices), the server problem notice from what the monitors say (public
  server names only), and, off until switched on, Suggest other names in the client area's domain search (free names
  close to a taken one, only the name sent, 5 presses an hour per client or address).
- ChatGPT (OpenAI) plugin for the AI assistant, off until switched on: your own API key from platform.openai.com and a
  model typed in or picked from the chat models Test connection lists.
- Setup guide: a Public area step after Client area and terms, ticked once Settings → Public area is saved.

## [0.2.0] - 2026-10-26

- Smaller backups: the module log, email log, sessions, cache and queued jobs go into a backup empty unless Settings →
  Backups → Include logs is on (a module log left on made one install's zipped backup 11 MB). Settings → Logging also
  sets how long the activity log (kept unless set) and the bell notifications (90 days) are kept, and the nightly
  prune drops expired cache rows. System health lists the biggest tables.
- Signed releases: the Release workflow signs each release's SHA-256 file (Ed25519, the `RELEASE_SIGNING_KEY`
  secret), and an update installs a release only when that signature matches a trusted key in `gaiacp.updates.keys`
  (`php bin/release-key keygen` makes the key pair; see `docs/releases.md#signing`).
- DeepSeek and Kimi (Moonshot AI) plugins for the AI assistant, off until switched on: your own API key and a model
  typed in or picked from the ones Test connection lists.
- AI menu on a text kept in several languages: Translate this text into the field's language (Greek typed in the
  English tab), and "Translate from" says the other language's text is empty instead of "Write some text first".
- Proxmox VE plugin (off by default) for selling VPS: each one is cloned from the KVM template its package names, gets
  an address from the server's pool under Settings → Proxmox → IP addresses (DHCP without one) and its password
  through cloud-init. The client gets a Proxmox login that sees only their VPS, for its console. Start, Shut down,
  Restart and Force stop in the Account menu; clients get Start, Shut down and Restart on their service page.
- AI assistant (Settings → AI assistant, with the new Claude plugin and your own Anthropic API key, off until switched
  on): an AI menu on the texts admins write that improves them (spelling, friendlier, clearer, shorter, more formal) or
  translates them, shown as a suggestion to edit before use; a switch per feature and a monthly token limit.
- CyberPanel servers (a new plugin, off until switched on under Settings → Plugins): websites with their owner users
  created, suspended, unsuspended and removed, package and password changes, usage, and Compare with the server. The
  server login is CyberPanel's `admin` with its password; new websites start on the PHP version under Settings →
  CyberPanel. Built from CyberPanel's source, not yet tried on a real server.
- Enhance server plugin (off by default): clients become Enhance customers, hosting services subscriptions with a
  website; create, suspend, change plan, terminate, password, one-click client login, usage and Compare with the
  server. Not yet tried on a real Enhance server.
- Dashboard: the Arrange cards button is now Show / hide cards, as it only picks the cards to show.
- A shorter README for people seeing GaiaCP for the first time (what it is, the demo, screenshots, installation in
  three steps); the full feature list moved to `docs/features.md`, installation and settings to `docs/installation.md`,
  the panel, registrar and import details to `docs/reference.md` and the release steps to `docs/releases.md`.
- Dashboard: the cards sit in two columns of the same width that each fill down on their own (no gaps under short
  cards), one after the other on a phone. Drag a card by its handle within or between the columns (or move it with the
  arrow keys) to put it where you want; the layout is saved for you at once. Arrange cards now only
  shows or hides cards; the widths and the reorder list are gone from it.
- Public demo: a cart filled before a reset is empty after it (sessions are kept, so it stayed).
- Security audit fixes: messages to clients show no HTML on the client area Home (an attribute such as onerror ran
  there, also for an admin looking as the client); the contact form's email carries only the ticket's link, none of
  the visitor's words, and one address gets at most 3 an hour; the email template preview runs no scripts; no other
  site may show GaiaCP in a frame; the session cookie is Secure on https installs; wrong REST API keys count as failed
  logins and banned addresses get no API answers; a visitor's domain hint on the public order page no longer asks the
  registrar; the demo closes Settings → Cookies and never adds its scripts; Livewire 4.4.8; Email to ticket 1.1.2
  treats an email the mail server marks as forged (DMARC / SPF) as from an unknown sender.
- Consistency pass: the Greek admin panel speaks in the singular everywhere (41 texts still used the polite
  plural), «περιοχή πελατών» in the texts that said «client area», grace and redemption fees named the same
  everywhere, «διαχειριστής» not «admin», cPanel/Plesk is the «control panel» (the dashboard stays the
  «πίνακας ελέγχου»), «Terms of Service» spelt the same; Settings → Automation is titled as its menu item;
  the client area's Billing menu lists Quotes after Credit; «πρόσθετο» for plugin in every Greek text.
- Public area: visitors switch between light, dark and system mode from an icon in the top bar, as clients do from
  their menu.
- Plugin catalogue (Settings → System → Plugin catalogue): find, install and update reviewed plugins from
  plugins.gaiacp.digital, built from the gaiacp-plugins repository. The catalogue and every zip are signed and checked
  before anything is installed; a daily check rings the bell once for each new version of an installed plugin.
- Admin guide: System → Help in the admin panel (and `docs/admin-guide/` on GitHub), fifteen chapters on setting
  up and running GaiaCP, from the first steps to billing, servers, domains, support, security and moving from WHMCS,
  with a search. In English and Greek; another language's chapters show once they are translated.
- Greek: the Add credit, Take off credit, Suspend, Unsuspend, Terminate, Reverse and Preview buttons showed in English.
- Compare packages: a row with each billing cycle's price (with the monthly share or setup fee under it) and a
  Choose button per package. A product can be marked Popular (Products → Order page), a badge on its order card, in
  the compare table and on the public home page.
- Public demo (`GAIACP_DEMO=true`, docs/demo-site.md): made-up data put back every few minutes by
  `gaiacp:demo-reset`, the demo logins filled in, a countdown bar, and nothing leaving the server (email to the log,
  no outgoing calls, plugin uploads, updates or backups). The Demo plugin's panel, registrar and monitoring load only there.
- A service's Record payment shows only when something is owed (an unpaid payment notice, or the due date within the
  days a notice is issued ahead); a client paying earlier is recorded with Price → Record early payment.
- Dashboard → Arrange cards: each admin puts the cards in their own order (drag them or use the arrows), picks how
  wide each is on a wide screen and hides the ones they don't need. Other admins keep their own layout; Back to the
  default undoes it. On a phone every card is full width, on a tablet two share a row.
- Announcements (Support → Announcements): news with a title and Markdown text per language, a publish date (empty =
  draft, a later date publishes it then) and Only for clients. Clients read them under Support → Announcements; with
  Settings → Public area → Announcements for visitors on, visitors read the others too, the newest three on the home
  page, and an RSS feed at `/announcements/feed`.
- Server status page (Settings → Public area, off until switched on): `/status`, for visitors and clients, with the
  state and the 30 day / 24 hour uptime squares of every active server a monitoring plugin checks, the ones with a
  problem first. It shows the server's name or its new Public name, never its hostname or IP address; Show on the
  status page (on the server's page) leaves one off.
- Changing a service's price on its page now updates its unpaid payment notice already issued (and so an unpaid
  order's total). Until a service is first paid its page also shows its First payment, which moves with the price when
  left as it was.
- Order page → Without tax: takes the tax off the order's unpaid payment notice (optionally making the client tax
  exempt from now on); Add tax puts the client's tax rule back.
- Product groups (Settings → Product groups): Shared hosting, VPS and so on, each with a name and description per
  language, an icon, its own link (`/order/<group>`, `/cart/<group>`) and the rows of a compare table. The order pages
  ask for the group first, then show its products as cards with their icons and a table comparing them; the public
  home page and side menu list products by group. Existing installs get one group per type, so nothing moves; the
  WHMCS import brings WHMCS's product groups (WHMCS import 1.0.8).
- Public area (Settings → Public area, each part off until switched on): a home page for visitors at the site root
  (welcome text per language, domain search box, products by type with their lowest price), domain search for
  visitors and a domain prices page. Visitors get a side menu and a top bar with a language picker, the cart and Log in;
  the public order page has the same side menu. With all parts off the site root stays the login.
- Public area, support: the knowledge base for visitors (an article can be marked Only for clients, which asks for a
  login) and a Contact us form (captcha, a hidden field against robots, three messages an hour per address, matching
  articles offered first). It opens a ticket without a client; its emails carry a secret link where the visitor reads
  the answers, replies and closes it without an account.
- VIES VAT check (a bundled plugin, off until switched on under Settings → Plugins): a check mark next to the client's
  VAT number asks the EU's VIES service whether it is valid (and fills an empty company name). With Settings → VIES VAT
  check → Reverse charge on, an EU business in another EU country whose VAT number VIES found valid pays no tax, and
  its payment notices say why ("Reverse charge: VAT to be accounted for by the recipient").
- Order forms say whether the domain typed is free (client Order page, public order page, admin New order): Available,
  Taken or Probably available by the field, and a warning on a transfer of a domain not registered yet. Clients can't put
  a taken domain in the cart to register, nor a free one to transfer; their lookups count in the domain search's 20 a
  minute, admins' don't.
- SSL with domains (Settings → Domains): an SSL certificate product offered when a client orders a domain on its
  own (a tick on the Order page and the public order page, "With SSL" in the domain search), at a discount on its
  first period; it is its own service in the same order and payment notice, renewed at its usual price.
- Domain search in the client area (Domains → Domain search): the name a client types with each TLD you sell, free or
  taken, with its price and Add to cart; a taken one offers a transfer. It asks the registrar when its plugin can tell
  (easy.gr, Enom), else the registry's RDAP server, else DNS (a domain without nameservers shows as probably free). With the captcha on for it (Settings →
  General → Captcha → Client area domain search, ticked on new installs), the first search asks for it and a solved one
  lasts 15 minutes; 20 searches a minute per client either way.
- Quotes in the client area (Billing → Quotes): the client's sent quotes, accepted or declined right there, and a
  bell notification for each new one. The REST API adds `leads` (a contact form on your website adds one, and the
  bell tells Sales) and `quotes` (write, send, accept for the client, decline).
- Quotes (Billing → Quotes): offers to a lead or a client with the items of a new order at the prices you set and
  one-off lines billed with the first payment. The client accepts or declines on the quote's page, from the link in
  the Quote email; accepting places the order (a lead becomes a client with the client area invitation). Sent quotes
  expire after their date (`gaiacp:expire-quotes`), Settings → Sales sets how long they are valid and their text.
- Billing cycle One time for other services (and their products): billed once, never renewed, and Completed once
  paid and set up. A quote's one-off lines become such services. "Other recurring" is now called "Other service".
- Leads (Clients → Leads): prospective clients with their source, notes, the admin who has them and the next contact
  day, when the bell and the dashboard remind you. Convert to client makes one a client (or links it to an existing
  one) and can email the client area invitation; Mark as lost keeps the reason. Settings → Sales sets the sources,
  Reports → Lead sources counts the leads and clients each brought, and a new access level, Sales, opens only leads.
- Guides for developers: [using the REST API](docs/using-the-api.md) (keys, the first call, placing orders and
  recording payments, with curl and PHP) and [a plugin tutorial](docs/plugin-tutorial.md) that builds a small plugin
  step by step.
- SSL certificates: a new service type, sold through the GoGetSSL plugin (a reseller account; off until switched
  on). A product of type SSL certificate picks a GoGetSSL certificate; once the service is set up (on payment by
  default) GaiaCP makes the private key and CSR, orders the certificate and emails the client how to validate the
  domain (DNS record, file on the website or email). The hourly `gaiacp:check-certificates` finds it issued and
  emails the client, issues the next one inside the order before a certificate ends (200 days at most) and orders
  the next period when the service is paid beyond the order. Admins have an SSL menu on the service (order, check,
  download, change validation, reissue, renew, cancel); clients see the validation steps, change the method and
  download the certificate in the client area.
- REST API (`/api/v1`): Settings → API keys makes keys for your own scripts and sites, each with its permissions per
  area (clients, services, orders, payment notices, payments, products) and the IP addresses it may call from. A key
  is shown once and can be revoked at any time; the list shows when and from where it was last used. Calls do what the
  panel does (orders, payments, suspensions…), with the same emails, activity log entries (by the key) and webhooks.
  Settings → API keys → Documentation describes every call, with an OpenAPI file to download.
- Order pages: products show their price per month, in bold, with what is billed each cycle under it (Settings →
  Client area → Show prices per month, on by default), and their description one line under the other.
- Client area: Email history in the client's menu lists the emails sent to them and opens each one (Settings → Client
  area → Email history, on by default; emails with a password or a sign-in link are listed without their text).
  Services and Domains have an "Order a new service" / "Order a domain" button, also on an empty list.
- Google Analytics: Settings → Cookies takes a measurement ID (G-…); the tag loads only for visitors who accept all
  cookies. Search engines: Settings → Client area → Search engines lets the public pages (login, sign up, order page)
  be indexed with a description and a link preview (company name and logo); /admin and signed-in pages never are.
- Client area: the cart and the notifications bell sit at the right end of the top bar.
- Client area and the public order page: a cart. Add to cart puts a product (with its domain and add-ons) or a domain
  in it; clients add as many as they need, remove what they don't want and place everything as one order with one
  payment notice. A cart icon in the client area's top bar shows how many items it holds. A visitor's cart on the
  public order page waits for them through sign up or log in.
- Client area: a notifications bell in the top bar, like the admins': new payment notices, payment reminders and
  overdue notices, payments received, services set up, suspended, active again or ended, domains about to renew and
  renewed, new prices, package limits, answers to tickets, cancellation and package change requests and changes of
  details. In the client's language; each client can turn it off in their profile, and Settings → Client area →
  Notifications bell turns it off for everyone. Payment notice reminders are now in the activity log too.
- Client area: in the Hosting list, a dot before each hosting account's domain shows its server's state now (green and
  pulsing while up, red while down, orange in maintenance), with the last 30 days' uptime when you point at it.
  It follows Settings → Client area → Show the server's uptime.
- GaiaCP is licensed under the GNU GPL, version 3 or later (`LICENSE`), with a contributing guide, a security policy,
  a code of conduct, issue and pull request templates, and a README that starts with what GaiaCP does and doesn't do.
- Setup guide (Settings → General → Setup guide, and a card at the top of the dashboard for super admins): the
  steps after `/install`, each opening the page where it is set, ticked off by themselves once done (a server added,
  the company's address filled in…) or by hand, and any of them can be skipped. Installs that already have clients
  start with the card hidden.
- GaiaCP is marked as alpha: a badge next to the version in the admin footer, in the installer and in
  `php artisan about`, and a note at the top of the README.
- Settings regrouped into General, Billing, Products & domains, Client area, Support, Email & notifications,
  Integrations, Security, System and Logs, with plugin pages in the group that fits, and a search box on the Settings page.
  Page addresses are unchanged.
- Domains: contact details (owner, administrative, technical and, at Enom, billing), private nameservers (ns1.your-domain
  with an IP address) and the registrar lock, from the admin's Registrar menu and the client's domain page, as in
  WHMCS. Each is offered only where the registrar can do it (easy.gr: contacts and private nameservers; Enom: all
  three) and has its switch under Settings → Client area → Domains (on by default).
- Settings → Client fields: your own fields on clients (text, several lines, a list of choices, yes / no or a date),
  with a label per language. Each can be required, hidden from clients, shown to them or filled in by them at sign up
  and in their profile, and shown on payment notices under the client's details (kept as they were on the day the
  notice was issued). The WHMCS import brings WHMCS's other client custom fields over as client fields.
- Settings → Languages: switch each language on or off, pick the default one and see how complete each is, GaiaCP's
  texts and each plugin's. Super admins download a language's missing texts and upload them translated, which adds a new
  language too; uploads are kept in storage, so updates never replace them. After a plugin update, its message says how
  many new texts each language misses. `gaiacp:translations <code> --plugin=<Folder>` checks one plugin.
- Settings → General → Region: the timezone (due dates and the daily run follow it), the date format (31/12/2026,
  31.12.2026, 12/31/2026, 2026-12-31 …) and the country new clients start in. The installer asks them, with the base
  currency. A phone number without a country code is sent to registrars with the client's country's code instead of
  Greece's. New installs start in UTC with no tax rule; an existing install keeps Europe/Athens, d/m/Y, Greece and its tax rule.
- GaiaCP runs with the document root on its own folder, at a domain's root or in a subfolder (`example.com/billing/`):
  a `.htaccess` sends everything into `public/` and keeps `.env`, `storage/` and the code private, and addresses never
  contain `/public`. The installer stops, and System health warns, when `.env` or `composer.json` can be read through
  the site. README → Installation → Document root has the nginx setup.
- The admin panel installs as an app (home screen on a phone, or from the browser on a computer), and each admin
  can turn on push notifications per device (Profile → Push notifications): the bell's notifications arrive even
  when GaiaCP is closed. On an iPhone they work once GaiaCP is added to the home screen.
- Telegram bot plugin: the events you pick sent to a Telegram chat (you or a group of admins), and quick read-only
  answers in that chat to /owed (who owes), /today, /tickets and /servers. Settings → Telegram, with Find chat ID
  and Send a test message.
- A hosting account's page in the client area shows its server's uptime for the last 30 days and 24 hours as the dashboard's squares, from the
  server's monitor (Settings → Client area → Hosting → Show the server's uptime, on by default).
- Email to ticket can also receive emails by webhook from a mail provider (Mailgun, SendGrid, Postmark), for when
  there is no mailbox to read over IMAP. Settings → Email to ticket → Receive by webhook makes the secret address.
- Better Stack plugin: a server links to its Better Stack Uptime monitor and shows Up / Down, with the bell when it
  goes down or comes back. Settings → Better Stack for the Uptime API token, with Test connection.
- Add-ons on the Order page and the public cart: clients tick add-ons under the product they order; they are in the
  same order and first payment notice, on the product's cycle, and are set up with it.
- HetrixTools plugin: a server links to its HetrixTools uptime monitor and shows Up / Down and its uptime, with the
  bell when it goes down or comes back. Settings → HetrixTools for the API token, with Test connection.
- Knowledge base: Support → Knowledge base for help articles in categories, in each language; clients read and search
  them in the client area (Support → Knowledge base), say whether one helped, and see matching articles while they
  write a new ticket. Settings → Support switches it off.
- Clients add add-ons themselves: Add an add-on on a service's page in the client area, with a payment notice for the
  days until the service's next due date (and the setup fee); the add-on is active once paid and renews with the
  service. Admins get the bell.
- Settings → Cookies: a cookie banner in the client area (off until switched on), with a link to your cookie policy
  and scripts such as Google Analytics that run only after the visitor presses Accept all
- Client area → Devices and sign-ins (in the client's menu): signed-in browsers with Log out and Log out other devices,
  and recent sign-ins including wrong passwords; the admin client page gets a Sign-ins tab with Log out everywhere
- easy.gr and ΑΑΔΕ plugins start off in a new install (`GAIACP_PLUGINS_START_ENABLED` turns them on); installs that
  have them keep their switch
- Slack and Discord plugins: the events you pick (new orders, payments, tickets, servers down and up, …) posted to a
  channel through its webhook, one short message each with a link to its page here. Settings → Slack / Discord, with
  Send a test message.
- Settings → Client area → Brand colour: the client area's buttons, links and highlights in your own colour.
- Settings → Failed jobs: background jobs that failed, with their error, Retry and Delete. Queued jobs now run from the
  scheduler every minute, with no queue worker.
- Events and webhooks: GaiaCP now raises events plugins can act on (orders, client sign ups, payment notices,
  payments, service status changes, cancellation and package change requests, domains, tickets, servers, failed panel
  calls). The new Webhooks plugin (off until switched on, Settings → Webhooks, super admins) sends them as signed JSON
  POSTs to the URLs you add, each with the events it wants: within a minute, retried after 1, 5 and 30 minutes, 2 and
  12 hours, with a delivery history, Resend and Test. An endpoint that keeps failing rings the bell once and is switched
  off after 50 failed tries in a row.
- Servers → Compare with the server fixes a difference in one click, asking first: unsuspend or suspend on the server
  or mark the service suspended / active in GaiaCP; take the server's package or set GaiaCP's on the server; take the
  server's domain; create a missing account on the server or mark the service terminated; suspend a running account
  no one is billed for. Changes in GaiaCP email nobody; nothing is ever deleted on the server.
- Tickets by email (Email to ticket plugin): GaiaCP reads a support mailbox over IMAP every minute. A client's answer
  to a ticket email joins the ticket, with its files and without the quoted earlier text; any other email opens a
  ticket in the department whose address it was sent to. Email from someone who isn't a client opens a ticket without
  a client (link one under Change; until then its emails go to the sender) or is ignored. Automatic replies are
  skipped and each sender may open a limited number of tickets an hour. Ticket emails carry the ticket's thread, and
  Settings → Support → Replies to ticket emails go to sets their Reply-To (a department's email wins).
- Support tickets: clients open a ticket from the client area (Support → Tickets) with a department, the service it is
  about, a priority and files, and reply or close it there. Admins work in Support → Tickets (Waiting for us,
  Assigned to me, Waiting for the client, On hold, Closed): Reply (from a canned reply, choosing the status after), Add
  note (admins only), Change (status, priority, department, admin, service) and Close; the ticket's page shows the
  client's services and what they owe, and the client page has a Tickets tab with Open a ticket. New emails Support
  Ticket Opened / Reply / Closed to the client; admins get an email and the bell for new tickets and client replies.
  Settings → Support: clients' tickets and priority on or off, admins' emails, close after 7 days without the client's
  answer (`gaiacp:close-tickets`, daily 07:00), file size and types, departments and canned replies. New access area
  Support tickets. The dashboard's Support card sits under the renewals table.
- Payment notices (the list and the client page's tab) now show every notice, newest first, with buttons over the
  table for All, Unpaid, Paid, Cancelled and Overdue, each with its count. All clears the filters in one tap. On a phone the buttons (here and on the services list) are
  smaller, on one row that scrolls sideways, and tables no longer show an empty band over their search.
- Client page in tabs: a summary at the top (open balance and how much is past due, active services, next renewal,
  email and phone), then Details (the form), Hosting, Domains, Other services, Orders, Payment notices, Payments and
  Activity, each with a count where it helps and shown only to admins with that access area. On a phone the tabs are
  a list to pick from.
- Domain costs: Settings → Domain pricing keeps what the registrar charges for each term (Registrar cost, optional).
  The list shows each TLD's renewal margin, in red with a warning when a term sells for less than it costs. Income
  forecast adds a Domain costs column and takes it off the profit, and the new Reports → Domain profit shows per TLD
  how many domains, what they bring in and cost a year, the profit and the margin.
- Client area: while a server is down or in maintenance on its monitor (Uptime Kuma, UptimeRobot), its clients see a
  notice at the top of every page naming their services on it; it goes away by itself when the server is up.
  Settings → Client area → Server problems turns it off or sets your own message. The Help page mentions it.
- New bundled plugin IP blacklist check: every hour each active server's IP address is looked up in DNS blacklists
  (Spamhaus ZEN, SpamCop, Barracuda, PSBL; editable), with a bell notification to super admins when one gets listed
  and when it is removed. Settings → IP blacklist check shows what is listed now and has Check now. Plugins can now
  put their own commands on the schedule (`Plugin::schedule()`).
- Servers card on the dashboard and the Servers list: the uptime and the accounts bars now look the same, as rows of
  small squares, each with its name and value ("Uptime, 24 hours 99.98%", "Accounts 3/5 (60%)"). In the list the
  uptime squares wrap onto two lines so they stay readable.
- Dashboard: the Orders and income chart adds Today, an hour by hour view with the income compared to yesterday by
  the same time. The chosen period (Today, 7 days, 30 days, 1 year) is remembered while signed in, and the dates under
  the chart now sit under their own bars.
- Settings → Plugins on a phone: each plugin's name, version and description take the full width, with its status
  and buttons under them; Enable / Disable are translated. Fixed: after uploading a plugin on a server with cached
  config, the page's buttons (e.g. Changelog) failed until the page was reloaded
- Plugins from outside GaiaCP: Settings → Plugins → Upload plugin installs a plugin zip (super admins, with their
  password and an "I trust this plugin" tick). The zip is checked first (nothing outside its folder, no symbolic
  links, size limits, a plugin.json with its version and class, every PHP file parses, it runs on this GaiaCP and
  isn't a bundled plugin), then its database migrations run and it starts disabled. Uploading a newer zip updates
  it (the version before is put back if its migrations fail); once disabled it can be uninstalled, keeping its
  tables and settings unless asked. Plugin folders in `plugins/` are found without changing GaiaCP's code, and the
  list marks each plugin Bundled or Uploaded. `GAIACP_PLUGIN_UPLOADS=false` turns uploads off.
  How to write one: docs/writing-plugins.md

- Server monitoring with UptimeRobot (a new bundled plugin, Settings → UptimeRobot: a Read-Only API key, which the
  Free plan has too, with Test connection). It works like Uptime Kuma: each server picks its monitor on its form,
  the Servers list and dashboard show its status and uptime bar with UptimeRobot's uptime percentage, and a server
  going down or back up notifies super admins in the bell
- An uptime bar for monitored servers: the Servers list shows a dot per hour for the last 24 hours, or a dot per
  day for 30 days (Uptime: 24 hours / 30 days above the list), and the dashboard's Servers card the 24-hour bar. Each
  dot takes its worst status (red down, amber pending or maintenance, green up, grey not checked) and shows its time
  and uptime on hover; next to the bar is the uptime percentage from Uptime Kuma (2.x), else from GaiaCP's own checks.
  GaiaCP keeps every 5-minute check for 31 days (removed by the daily log clean-up)
- Fixed: choosing Uptime Kuma as a server's monitoring service showed "Error loading page" once its monitors had
  been read into the cache
- Server monitoring with Uptime Kuma (a new bundled plugin, Settings → Uptime Kuma: the address and an API key, with
  Test connection). Each server picks its monitor on its form (pre-selected when one checks the server's hostname or
  IP); every 5 minutes GaiaCP reads the statuses, the Servers list shows Up / Down / Pending / Maintenance (Check
  status now reads them at once) and the dashboard's Servers card a dot per server. A server going down, and coming
  back up, notifies super admins in the bell; Uptime Kuma itself not answering does too, once until it answers again
- Fixed: on a phone the settings Save bar's orange dot was squashed and off the note's first line, and the buttons
  stacked; the note now takes its own line over Cancel and Save
- A public order page at `/cart` (Settings → Client area → Public order page, off by default): visitors choose a
  product and its domain, or a domain on its own, from the client area's catalogue without logging in, with prices
  in the base currency. Continue takes them to sign up (or to log in while sign up is off; the login and sign up pages
  show what is waiting), and once in, the Order page opens with their choice filled in, where they accept the terms
  and place it as usual. `/cart?product=<id>` pre-selects a product, for links from your website; signed-in clients
  go straight to the Order page
- Fixed: the admin's menu showed Settings and Keyboard shortcuts in English whatever the panel language, and the top
  bar search's text sat above the middle of its field
- A bell in the admin top bar, after New order, with the unread count. It tells admins about new client orders and
  sign-ups, client change requests, payments made online, online payments to match by hand, failed panel or
  registrar calls, domains expiring within 7 days (checked by the daily run), and failed backups or updates. Each
  goes only to admins with access to that part (server, backup and update failures to super admins), never to the
  admin who did it; each opens the order, client, payment notice or service. Mark as read, mark all as read and
  clear are in the bell's panel. Every admin switches it on or off in Profile (or Admin users → Notifications in
  the panel)
- The services list (Hosting, Domains, Other services) is laid out like the redesign: each tab has its own title and
  description and counts what it lists, with chips above the table for All, Active, Suspended, Pending and Due in
  14 days (each with its count; they set the filters). The table starts with the service (domain and username), then
  the client with their company, the product, the server with its panel, the price, the next due date with how near
  it is ("In 5 days", "3 days overdue") and the status, with the row's actions under one menu. Export CSV downloads
  the rows shown. The "Due in the next 30 days" filter is now 14 days
- Settings pages are laid out like the redesign: "Settings / <group>" over the title, the page's description under
  it, the sub-menu as a card of plain links. Save stays in view at the foot of the screen; once you change a field it
  says you have unsaved changes and offers Cancel, which puts the saved values back
- The admin dashboard is laid out like the redesign. It greets you by name and the day, then shows:
  - the open balance (unpaid payment notices, how much is past due, what falls due in 7 days)
  - a chart of new and completed orders and income over 7 days, 30 days or a year, compared with the period before
  - the renewals table, the servers with their accounts against the maximum
  - "Needs attention": pending orders, client change requests, overdue notices and what failed in the last 3 days
  Each card shows only to admins with access to it
- The admin top bar is laid out like the redesign: the logo over the sidebar, then a wide search ("Search clients,
  services, domains…", Ctrl+K or ⌘K) and Client area (a new tab) and New order buttons at the top of the main area.
  New order shows only to admins with Clients and orders access; on a phone the buttons are icons
- The admin sidebar is grouped like the redesign: Main (Dashboard, Clients, Orders), Billing (Payment notices,
  Payments, Reports), Services (Hosting, Domains, Other services) and System (Activity log, Settings). Orders shows how
  many are pending. With the Gaia theme your name and menu sit at the foot of the sidebar; avatars show your initials,
  drawn by GaiaCP instead of loaded from ui-avatars.com
- The Flat theme now has the new layout too (grouped menu, your card at the foot of the sidebar, the inset main area)
  in the original emerald and grey, without shadows or motion
- Themes for the admin panel. Gaia, the new design, is the default: neutral light grey (black and grey in dark mode),
  the logo's teal, rounded cards, Literata headings and quiet motion (off when the device asks for reduced motion).
  Flat is the look until now. Each admin picks theirs in Profile → Panel theme; Settings → General → Default theme
  sets it for everyone else. Each theme is a plugin (Settings → Plugins → Themes)
- The admin panel shows the GaiaCP logo (a light version in dark mode) and its tab icon, and "Powered by GaiaCP"
  has the hexagon mark
- A hosting product can now have several servers (Products → Servers, the ones with its package) in place of one
  default server. A new order, a new service and a client's order go to the least full active one and skip full
  servers; you can still pick another in New order and Complete order. Existing products keep their server
- Complete order: the window now lists each pending domain with its registrar and each pending hosting account with
  its server (accounts used / maximum) and package, pre-selected as they are. Change them there and Confirm; the
  choice is saved on the item before the domain is registered or the account created, so there is no need to edit
  the service first
- Fixed: Settings → Client area → Terms of service would not save with only the text filled in (the browser still
  asked for the link)
- Fixed: the browser filled its saved login into the API token / password field of a server (and other secret
  fields such as registrar, SMTP, FTP and zip passwords), so the edit page showed a value that was not the stored
  token and saving would replace it. Those fields now turn off autofill; the stored secret is still never shown
- Terms of service (Settings → Client area): a link to your terms and / or their text, and a version. While it is
  on, clients tick "I accept the Terms of Service" before they place an order in the client area or sign up, and the
  order (or the new client) keeps when they accepted and which version, shown on the admin's order and client pages.
  The version becomes the date and time by itself when the terms change, unless you write your own
- Servers: each server can have a maximum number of accounts, a monthly cost, its IP address and a status page
  (all optional). The Servers list shows its hosting accounts against the maximum ("33/120 (28%)" with a bar), and
  New order and the service form show them next to each server. **Log in** on the list opens WHM,
  Plesk (signed in) or HestiaCP's login page in a new tab. Income forecast shows the profit of each month after the servers' costs,
  and Server revenue each server's cost and profit
- Fixed: the price table of Settings → Domain pricing (and its prices in other currencies) was squeezed on a phone;
  there each term now has its own row above its three prices
- Currencies, like WHMCS's (Settings → Currencies): EUR stays the base currency and other currencies can be added
  with their prefix, suffix, number format and rate to the base. Update exchange rates reads the European Central
  Bank's rates (also daily, under Settings → Automation) and Update product prices sets every product's and TLD's
  prices in the other currencies from the base ones. Each client has a currency (on the client's page, while it has
  no services or payment notices): its orders, services, payment notices, payments and card payments are in it, and
  products and TLDs can have their own prices in it (empty = the base price at the rate). Dashboard totals and
  reports add other currencies in the base one at today's rate
- Fixed: an order item already paid (through its order's payment notice, say) no longer offers Record payment while
  it waits to be set up, which would have charged another cycle; its First payment shows Paid
- Domain pricing per TLD instead of per registrar: Settings → Domain pricing lists every TLD with its register,
  transfer and renew prices for 1, 2 and 3 years, its grace and redemption fees and a preferred registrar. New orders
  pre-select the TLD's preferred registrar (the admin can change it on the order) and offer only the priced terms. The
  prices and fees each registrar had move there, with that registrar as the preferred one
- Email Notifications, a built-in registrar (a bundled plugin) for TLDs with no integrated registrar, as in WHMCS:
  registering, transferring, renewing, nameserver changes and EPP code requests are emailed to the admins and nothing
  is booked at any registrar
- Client area maintenance mode, like WHMCS's (Settings → Client area): while it is on, every client area
  page, the login and sign up included, shows your message (or a built-in one in the visitor's language), or sends
  clients to an address you set, such as a status page. /admin is not affected, admins signed in there still see the
  client area (with a note that it is closed to clients), and Stripe payments already under way are still recorded
- Settings → Payment methods, right after Company: Bank transfer, Stripe and any other payment plugin in one list
  with whether each is on, each opening its own settings; in the Settings menu they now sit under Payment methods. Stripe, Bank transfer and Google sign-in have an On
  switch at the top of their settings page (the same as Enable / Disable under Plugins, super admins only)
- New order (admin) and the client area's Order page show the first payment with tax, as the payment notice will
  ask for it: "€100.00 + ΦΠΑ 24% €24.00 = €124.00 with tax", using the client's tax rule (none for a tax exempt
  client or while Charge tax is off)
- Orders list and order page show the total with tax, as on the order's payment notice ("€200.00 + ΦΠΑ 24% €48.00"
  under the total in the list); the order confirmation and new order emails give the total the same way
- HestiaCP servers, a new bundled plugin: hosting accounts are created (a HestiaCP user on the service's package,
  with the service's domain), suspended, unsuspended, terminated, moved to another package and given a new password,
  like on cPanel and Plesk, and their usage (disk, bandwidth, mail accounts, databases, web domains) shows on the
  service. It logs in with a HestiaCP access key (or the admin password). HestiaCP has no one-time login link, so the
  client area's "Log in to HestiaCP" opens its login page, where the client signs in with their own password
- Login security (Settings → Login security, super admins): like WHMCS, 3 wrong passwords or two-factor codes from
  one IP address within 15 minutes, on the admin or client area login, ban that address for 15 minutes (both numbers
  can be changed, or banning switched off). A banned address sees "Your IP address … is blocked" with the reason and
  when the ban ends in place of the login, sign up and forgot password pages; anyone already logged in stays in. An
  allow-list of addresses or ranges (CIDR) are never banned. Settings → Banned IPs lists the bans, lifts one, or bans
  an address by hand (for a set time or until lifted). Bans and lifts go in the activity log
- Captcha: Cloudflare Turnstile now shows on the forms (it stopped with "Remove async/defer … before using
  turnstile.ready()" in the browser console)
- Card payments with Stripe, a new bundled plugin: clients press "Pay by card" on an unpaid payment notice in the
  client area and pay on Stripe's own payment page (no card details reach GaiaCP). The payment is recorded on the
  notice once Stripe confirms it, through its signed webhook or when the client comes back, exactly once, and renews
  domains and unsuspends hosting like a payment recorded by hand. Settings → Stripe (super admins) holds the mode
  (test or live), each mode's secret key and webhook signing secret (stored encrypted), the webhook address and the
  events to register, and a Check the key button. A card payment that no longer fits its notice (paid another way
  meanwhile) is not recorded and is listed in the admins' daily summary to record by hand or refund. The client Help
  page mentions it while it is set up
- Company logo: the logo, dark mode logo and tab icon can be SVG. Anything that could run or load from elsewhere is
  removed on upload, and the SVG must say its proportions (a viewBox, or a width and height) so it scales cleanly.
  Emails get a PNG copy where the server has the PHP imagick extension (System health lists it), else the name
- Settings → General, first on the Settings page: "Create payment notices … days before the due date" (moved from
  Settings → Billing, which is gone; its old address opens General) and, for super admins, a Captcha dropdown: none, Google reCAPTCHA or
  Cloudflare Turnstile, and the forms it shows on (client area login, sign up and forgot password by default; the
  admin login and forgot password can be ticked). Each captcha is a new bundled plugin with its own keys page
  (Settings → Google reCAPTCHA, Settings → Cloudflare Turnstile, secret key stored encrypted); the dropdown offers
  only the ones that are on and have their keys. A failed login asks for a new check
- Sign up: with Settings → Client area → New clients can sign up on (off by default), the client area's login page
  has a "Sign up" link. New clients fill in their details (name, email, phone, address, optional tax details) and a
  password, then confirm their email address from the new Email Address Verification email before anything opens.
  An address that is already a client or a login can't sign up again. Admins get a New client email (Settings →
  Automation). Logins made before, or by an admin, count as confirmed
- Google sign-in, a new bundled plugin: "Continue with Google" on the client area's login and sign up pages, once
  Settings → Google sign-in has your Google OAuth client ID and secret (that page shows the redirect URI to register).
  It logs in the client whose email address Google confirms, gives a client without a login one, and sends a new
  address to the sign up form with the address fixed and no password. Admins who aren't clients, logins turned off
  and logins with two-factor authentication on are refused
- Fixed: a plugin's secret setting stored only by the plugin (not listed in the core) was sent back to its settings
  form and a blank field cleared it
- Behind Cloudflare: GaiaCP now trusts Cloudflare's addresses, so logins, sessions, error logs and the Plesk one-click
  login see the visitor's own IP, and https stays https. Other addresses can't fake theirs. `GAIACP_TRUST_CLOUDFLARE=false`
  turns this off; `GAIACP_TRUSTED_PROXIES` adds another proxy. Settings → System health shows whether Cloudflare is in
  front and warns when its SSL mode is Flexible
- Company logo: Settings → Company takes the logo, an optional light version for dark mode and a browser tab icon.
  The logo replaces the name on the client area's login page and menu (the admin panel keeps GaiaCP), heads printed
  payment notices and tops every client email. The logo from Settings → Mail moves there on update (that upload is
  gone from Mail)
- Hosting usage: a hosting account's page shows its disk space and this month's bandwidth against the package's
  limits as rings, and email accounts, databases and their space, websites, addon / parked domains and subdomains as
  tiles, read from cPanel or Plesk (kept 15 minutes; Read again on the admin side). Clients see it on their service
  page unless Settings → Client area → Show usage is off; cPanel and Plesk plugins 1.2.0
- Fixed: nameserver fields accepted any text. On the domain form, New order, Registrar → Change nameservers and
  Settings → Domains each one must now be a host name like ns1.example.com, two to four of them, saved lowercase
  without repeats. An active domain's nameservers are read-only on its form, since Save never reached the
  registrar: change them with Registrar → Change nameservers
- Fixed: editing a registrar showed an empty Username, so the login looked lost. The stored username now shows;
  the password still stays hidden (empty keeps it), as before
- Two-factor reminder: admins and clients without two-factor authentication see a notice at the top of the panel
  with Set it up (their profile) and Not now, which closes it for good
- Fixed: on Order, a domain offered for 2 years only (a .gr transfer) kept the 1-year term and refused the order;
  the term now moves to one the domain is offered for. Form errors in the client area and panel show in Greek
- Order: "A domain name only" chosen by mistake can be undone with "I don't want a domain", which goes back to
  the list
- Client area links and help: client emails end with a "View in the client area" button that opens the payment
  notice, service or domain they are about (only for clients who can log in; Settings → Client area → Links in
  emails). Home has shortcuts to the main pages, and a new Help page explains the client area in English and Greek,
  leaving out what you have switched off
- New client has a Client area password: filled in, the client gets a login at once and, unless you untick it, the
  new Client Area Login Details email with the address, username and password (its switch under Settings → Email
  templates sets the default). An admin user can also be a client: inviting or adding a client with an admin's email
  links that admin, whose one login then opens both /admin and the client area
- Client orders: an Order page in the client area with the products shown there that are not hidden or retired, each
  with the billing cycles it has prices for. Hosting comes with a domain the client keeps, registers or transfers;
  a domain can also be ordered on its own at your registrar prices. The order goes through the same steps as New
  order (payment notice emailed at once, Auto setup); domains are registered or transferred when you complete it.
  You get an email for each new order (Settings → Automation → New order email); Settings → Client area → Clients
  can order turns the page off
- Client hosting self-service: on their own active hosting, clients open cPanel or Plesk already signed in (a new
  one-time link each time) and change the account password. Both have a switch under Settings → Client area, on;
  cPanel and Plesk plugins 1.1.0
- Client domain self-service: on their own active domains, clients change the nameservers, get the EPP code and turn
  auto renew off or on (off, the domain gets no more payment notices and ends at its due date). Each one has a
  switch under the new Settings → Client area, all on; every change is in the activity log with the client's login
- Client profile: clients change their email (with their password) and phone at once; changes to their name, company,
  ΑΦΜ / Δ.Ο.Υ. or address wait for you. You get an email (Settings → Automation → Change request email), the Clients
  menu shows how many wait, and Review requested changes on the client approves or declines them
- Client area pages: Home shows what the client owes and what renews next; Services, Domains (expiry, nameservers,
  auto renew), Payment notices (open one, print it or save it as PDF, with how to pay) and Payments list their own
  records only. Services switched off for the client area (Maintenance) never show
- Client logins: a client page's new "Client area" menu invites the client (an email with a link to choose a
  password, Greek for Greek clients), turns their login off or on, and lets you log in as them. The client area
  opens at the site root with its own login, forgot password and profile; for now its Home only welcomes them
- Payment methods are plugins: Bank transfer is the first (Settings → Plugins), and its bank details moved from
  Settings → Billing to Settings → Bank transfer (saved text is kept). Switched off, it is no longer offered when
  recording a payment and its details leave the emails; payments already recorded keep it
- Plugin versions: every plugin has its own version and changelog, shown under its name on Settings → Plugins
  with the date it was installed or updated (updates are logged); a plugin that needs a newer GaiaCP stays off
- Show in client area: a switch on every product and service (off for Maintenance, including existing ones), ready
  for the client area; a service takes it from its product and can change it
- Installer: a new copy opens `/install` (requirements check, database, business, first admin, cron line), in English
  or Greek, and locks itself when done; `php artisan gaiacp:install` does the same from SSH
- Updates: Settings → System → Updates shows the latest release and its notes, and Update backs up the database, installs
  it and runs the migrations (putting the old code back if a step fails); `php artisan gaiacp:update` from SSH
- Greek client emails: every email template has built-in Greek text, sent to clients whose email language is Greek
  unless you saved Greek text of your own; the preview shows its tables in the language previewed
- Admin access levels: a super admin opens everything; other admins open only the areas ticked on their user under
  Settings → Admin users. Existing admins become super admins

## [0.1.0] - 2026-10-10

The first numbered release: everything built so far.

- Clients, products with prices per billing cycle and setup fees, admin orders (domain, hosting and maintenance in one go)
- Hosting on cPanel / WHM and Plesk: create, suspend, unsuspend, terminate, change package and password
- Domains at easy.gr: register, transfer, renew on payment, nameservers, EPP code, daily expiry sync
- Payment notices (proformas, not legal invoices) with reminders and overdue notices, tax rules, manual payments
- Daily automation: notices, reminders, auto-suspend, auto-terminate, daily summary email to the admins
- Email templates (WHMCS-style placeholders, per language), mail layout and SMTP settings
- Reports: income, forecasts, revenue per server, Domains and Services reports with CSV export
- Backups (zipped SQL to FTP / email), System health, module / email / error logs
- Admin users with two-factor authentication and password reset
- Plugins: cPanel, Plesk, easy.gr, ΑΑΔΕ lookup and the WHMCS import, each with an on/off switch
- English and Greek panel
