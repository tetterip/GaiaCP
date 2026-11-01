# Stack and panels

How the admin and client panels, users, access, security, branding, themes and languages are built. Read when working on any of them.

- Laravel 13, PHP **8.3+** (the `User` PC ships PHP 8.2: upgrade it before running this app there)
- SQLite in development, MySQL/MariaDB in production
- Admin UI is **Filament 5** at `/admin`; no public order form. The client area is a second Filament panel at the site root
  (`ClientPanelProvider`, id `client`, guard `client`, password broker `clients` with 48-hour links, pages in
  `app/Filament/Client/`). Home (`/dashboard`: announcements from Messages to clients, amount owed, active count, next renewals), Services, Domains, Payment
  notices (`resources/views/filament/client/payment-notice.blade.php`, printed / saved as PDF by the browser with print
  CSS) and Payments: read-only resources using `ScopedToClient` (only the signed-in client's records, another's id is a
  404; services and domains only `Service::clientVisible()`, cancelled ones left out; notices show every line). The
  client's Profile (`Client/Pages/Auth/EditProfile`) saves email (current password asked) and phone on the client at once;
  `ClientChangeRequests::FIELDS` (name, company, tax details, address) become a pending `client_change_requests` row
  (one per client, `fields` = field => [old, new]) that an admin approves or declines from the client page ("Review
  requested changes"; Clients menu badge), emailed to admins (`ClientChangeRequestMail`, Settings → Automation → Change
  request email). Cancellation requests (`CancellationRequests`, `cancellation_requests`, at most one pending per
  service): "Request cancellation" on an active / suspended service that is not a domain and not ending already
  (`Client/Actions/RequestCancellation`), Immediately or At the end of the billing period, with a reason and optionally
  the same-name domain (`TransferService::domainOf()`) not to renew; the client can withdraw it while pending. Admins
  approve or decline in Services → Cancellation requests (Open / Completed tabs, menu badge): Immediately terminates
  (`ServiceLifecycle::terminate()`, a panel failure keeps it pending), the end of the period sets `auto_terminate`, the
  domain gets Auto renew off; the client gets Cancellation Request Approved / Declined. Admins get an email (Settings →
  Automation → Cancellation request email) and the bell. Upgrade requests (`UpgradeRequests`, `upgrade_requests`, one
  pending per service): "Upgrade / downgrade" (`Client/Actions/RequestUpgrade`) on an active service that is not an add-on
  or ending, offering `ChangeProduct::products()` that are `clientOrderable()` at `ChangeProduct::price()` (kept on the
  request); Services → Upgrade requests approves (price editable, "charge the difference now", runs `ChangeProduct`) or
  declines; Upgrade Request Approved / Declined to the client; admins get an email (Settings → Automation → Upgrade
  request email) and the bell (`service.upgrade_requested`). Support tickets (`App\Actions\Tickets`; `tickets`,
  `ticket_messages`, `ticket_attachments`, `ticket_departments`, `canned_replies`; numbered `T-000123` from the id):
  the client's Support → Tickets (`Client/Resources/Tickets`, behind `gaiacp.support.client_tickets`) opens a ticket
  (visible department, one of their services, priority if `client_priority`, files) and replies or closes it; admins
  work in Support → Tickets (`Resources/Tickets`, area `AdminArea::Support`; tabs Waiting for us / Assigned to me /
  Waiting for the client / On hold / Closed; the page shows the thread `filament.tickets.thread` beside the client's
  services and what they owe; Reply with a canned reply and the status after, Add note (internal, never shown or
  emailed to the client), Change, Close) and open one for a client (client page Tickets tab). Who wrote last sets the
  status: the client → Open ("Awaiting reply"), an admin → Answered; a reply opens a closed ticket again. Files go on
  the private `local` disk under `tickets/` (`Filament/Tickets/TicketFiles`, which takes only paths just uploaded by the field, never a string the browser sends; Settings → Support size and types) and are
  downloaded only through `TicketAttachmentController` (admin: Support area; client: own ticket, never a note's).
  Emails: Support Ticket Opened / Reply / Closed to the client (`ClientAreaLinks` links to the ticket); `TicketMail` to
  the assigned admin, else every Support admin with Receive system emails (Settings → Support → Email the admins); bell
  `ticket.opened` / `ticket.client_replied`. `gaiacp:close-tickets` (daily 07:00) closes Answered tickets older than
  `support.auto_close_days`. Departments and canned replies (`{client_first_name}`, `{ticket_number}`, … filled by
  `Tickets::fill()`) are under Settings → Support; the dashboard's Support card sits under the renewals table.
  Tickets by email: the Email to ticket plugin (`plugins/TicketMail`, IMAP through `directorytree/imapengine`, no PHP
  imap extension) runs `gaiacp:fetch-ticket-mail` every minute; `InboundMail` adds an answer to its ticket
  (`Ticket::idFromEmailReferences()` on In-Reply-To / References, else `[T-000123]` in the subject, only from the
  ticket's client or sender, `Tickets::replyFromEmail()`) or opens one (`Tickets::openFromEmail()`, client by email
  address, else none: `tickets.email` / `name`, linked later under Change). `ticket_messages.email_message_id` keeps a
  message from being added twice. `InboundWebhook` (`POST /webhooks/ticket-mail/{token}`, `ticket_mail.inbound_token`
  encrypted, made and shown once on the settings page, throttled) passes the raw MIME from Mailgun `body-mime`, SendGrid
  `email`, Postmark `RawEmail` or a message/rfc822 body to the same `InboundMail` as a `FileMessage`. Ticket emails to clients carry `Message-ID` / `References` with the ticket's thread id
  and Reply-To the department's email or `support.reply_to`. Knowledge base: `kb_categories` (`names` per language,
  Settings → Knowledge base categories, `KbCategoryResource`) and `kb_articles` (`texts` per language like Messages to
  clients, unique `slug` made from the default title, `published`, `views`, `helpful_yes/no`), written in Support →
  Knowledge base (`KbArticleResource`). `search_text` keeps every title and text lower case without accents
  (`KbArticle::searchable()`, filled on save; JSON columns escape Greek, so LIKE can't search them), `matching()` needs
  every word. Client area: Support → Knowledge base (`Client/Pages/KnowledgeBase`, `?q=` search) and
  `/knowledgebase/{slug}` (`KnowledgeBaseArticle`: Markdown with HTML stripped, views and "Was this helpful?" once a
  session, not while an admin views as the client, others in its category, Open a ticket), only while
  `support.knowledge_base` is on and an article is published (`App\Support\KnowledgeBase::enabled()`). Open a ticket
  shows up to 3 articles matching the subject's words (`KnowledgeBase::suggestions()`). Downloads: `download_categories`
  (`names` per language, Settings → Download categories, `DownloadCategoryResource`) and `downloads` (`texts`
  {locale: {title, description}}, `path` on the `local` disk under `downloads/`, `file_name`, `size`, `visibility`
  `App\Enums\DownloadVisibility` everyone / clients / products with `product_ids`, `published`, `download_count`),
  in Support → Downloads (`DownloadResource`, `checkFile()` keeps only a fresh upload's `downloads/<40 chars>` path or the
  one already stored). `Download::visibleTo($client)`: everyone for visitors, plus clients' and those of a product the
  client has an Active or Completed service of. Client area: Support → Downloads (`Client/Pages/Downloads`,
  `OpenToVisitors`, shown while one is visible) and `/downloads/{id}/file` (`DownloadController`, 404 unless visible,
  counts). Deleting a download, or replacing its file, deletes the old file. Domain self-service (`Client/Actions/DomainSelfService` on the domain page): nameservers and EPP code
  through `DomainLifecycle` (active domains at a registrar here, not `external`; a failure shows a plain message),
  auto renew through `SetDomainAutoRenew`; each behind a `gaiacp.client_area.*` switch (Settings → Client area,
  `ClientAreaSettings`). Hosting self-service (`Client/Actions/HostingSelfService` on the service page, active accounts on
  an enabled panel): Change password through `ServiceLifecycle::changePassword()`, and "Log in to cPanel / Plesk" =
  `Client\PanelLoginController` (`filament.client.services.panel-login`, a new tab) → `ServiceLifecycle::loginUrl()` →
  `HostingProvisioner::loginUrl()` (WHM `create_user_session`; Plesk `create_session` for the subscription's customer
  only), a fresh one-time link per click, never stored or logged. Usage (disk space, this month's bandwidth, against
  the limits): `App\Livewire\HostingUsage` (lazy, on the admin service form and the client service page behind
  `gaiacp.client_area.hosting_usage`; the Server uptime section (`ServiceResource::uptime()`, `filament.client.server-uptime`: the dashboard's square bars for 30d / 24h lighting up in turn, server name never shown) behind `gaiacp.client_area.hosting_uptime`; Read again is admin only) → `ServiceLifecycle::usage()` (cached `USAGE_MINUTES`,
  not logged) → `HostingProvisioner::usage()` (`AccountUsage`: disk and bandwidth in bytes plus `counts` by `COUNTS` key,
  null limit = unlimited). Usage warning: `App\Actions\CheckUsage` (`gaiacp:check-usage`, daily 06:00, off when
  `gaiacp.automation.usage_warning_percent` is empty) reads every active hosting account fresh; disk or bandwidth at or
  above that share not yet in `usage_warnings` (service, kind, `Y-m`) sends the Usage Warning email and logs
  `service.usage_high` (bell, Services area)
  A group's public page (`product_groups.page`, `page_titles` / `page_descriptions` / `page_texts` per locale, `faq`):
  `ProductGroupPage` at `/{group}`, registered last in the client panel's routes as a `->fallback()` so it never hides
  another route (`ProductGroup::reservedSlugs()` refuses those links); cards, `OrderForm::compareTable()` with Order
  links (`compare-products` without `$field`), questions; `metaDescription()` and `structuredData()` (ItemList of
  Product + Offer, FAQPage) are read by `Seo` through the page captured from the layout. Linked from PublicHome, the
  visitor's menu and the sitemap.
  A product's page (`products.page`, `slug` unique, `page_titles` / `page_descriptions` / `page_texts`): `ProductPage` at
  `/{group}/{slug}`, also a fallback route, only for `clientOrderable()` products with cycles; `noindex()` (read by
  `Seo::kept()`) while no language has a text, which also keeps it out of the sitemap and drops its JSON-LD (Product +
  Offer, BreadcrumbList). `Concerns\PublicProducts` holds `orderUrl()` / `offer()` for both pages. Products keep
  `name` / `description` in the default language and `names` / `descriptions` for the others: client-facing order
  pages read `localName()` / `localDescription()`; admin pages, services and emails keep `name`.
  Product groups (`ProductGroup`, Settings → Product groups `ProductGroupResource`; `products.product_group_id`, `icon`,
  `features`): `App\Support\OrderCatalogue::groups()` lists them in order with their products (cheapest a month
  first), the ungrouped ones under `OTHER` and domain names as `DOMAINS`; hidden groups only when asked for by slug.
  `OrderForm` asks for the group first (`group`, a card each) once some product is in a group and there is more than
  one to pick; without groups it lists every product and "A domain name only" as before. The group comes from the
  route (`/order/{group?}`, `/cart/{group?}`) or the product (`OrderCatalogue::keyFor()`). A group with `features`
  shows `filament.client.compare-products` under its products: the feature rows, a price row per billing cycle any of
  them has, and Choose buttons that `$set` the product field (so its `afterStateUpdated` runs as for a card). A product's
  `popular` adds a Popular badge to its card, the table and the public home page, followed by its visible tags
  (`ProductTag`, Settings → Product tags `ProductTagResource`, `product_product_tag`; `Product::badges()`, at most
  `Product::BADGES`, partial `filament.client.product-badges`, colour from `ProductTag::COLORS` as `--gcp-tag`); every tag
  also filters Products, Services (`product_tags`) and Messages to clients (`filters.tags`). Icons: `App\Support\ProductIcons`.
  A product's `color` (a `ProductTag::COLORS` key, `Product::orderColor()`) is `data-gcp-color` on its card, compare
  column and public home card; filament.themes.client turns it into `--gcp-product` and the local `--gcp-accent`.
  Both colour pickers are `App\Filament\Forms\ColorCircles`.
  Orders (`Client/Pages/Order`, `gaiacp.client_area.orders`): one product from `App\Actions\ClientOrder::products()`
  (`Product::clientOrderable()`, not domain type, a priced cycle) with its domain kept / registered / transferred, or a
  domain on its own, plus the product's options not hidden from clients (`ProductOptionFields`, `options.{id}`);
  Add to cart checks it with `ClientOrder::items()` and keeps it in the session cart (`ClientOrder::addToCart()`,
  `client_order.cart`, keyed items, EPP code encrypted, at most `CART_LIMIT`, a domain registered / transferred only
  once) and opens the cart (`Client/Pages/Cart`, `/checkout`, top bar icon `filament.client.cart-icon` with the count
  on `GLOBAL_SEARCH_AFTER`; items listed by `filament.client.cart-lines` with Remove). Place order there runs
  `ClientOrder::place()` with all the items (`cartItems()`: each item's add-ons keep pointing at its own product), so
  they are one order with one payment notice; `ClientOrder` prices it from the product, its options and
  `RegistrarTld` (never the form), refuses a domain already here, runs `PlaceOrder`, logs `order.client_placed` and
  sends `NewOrderMail` to admins with Clients access (Settings → Automation → New order email). An item that can no
  longer be ordered shows its reason in the cart and stops the order. Domains are checked and registered only on
  Complete order.
  Public order page (`Client/Pages/PublicOrder`, `/cart`, route `filament.client.cart`, 404 unless
  `gaiacp.client_area.public_orders` and `ClientOrder::publicEnabled()`): the same form (`Client/Concerns/OrderForm`,
  base currency, no terms) for visitors, filling the same session cart (listed in the summary card); Continue (which
  adds what is filled in when the cart is empty) sets `url.intended` to the cart and goes to sign up (login while
  sign up is off; both show `filament/client/cart-notice`), so a client still places it.
  Menus (Settings → Client area → Menus, `MenuSettings`, `App\Support\ClientMenu`): `client_area.menu` and
  `public_area.menu` hold `{items, seen}` (null = as it comes). Rows are `page` (a built-in entry by key: the class
  basename of a client panel page / resource, or `ServiceResource.hosting` style when a class has several items; for
  visitors a key of `PublicArea::menuEntries()`), `heading` (a built-in one by key or your own), `product`,
  `category` (product group) and `link`, each with optional `label` per locale and `icon` (`ClientMenu::iconOptions()`).
  `ClientMenu::build()` clones the built-in items (their own visibility checks still apply), sorts everything in the
  saved order and drops empty headings; a page not in `seen` (added by an update) goes to the end of its section. The
  settings page is a Builder with block previews (`App\Filament\Forms\MenuRowBlock`): fields render only in the edit
  window, so a long menu stays light.
  WHOIS (`Client/Pages/WhoisLookup`, `/whois`, `public_area.whois`, visitors and clients): `App\Support\Whois` asks
  the TLD's RDAP server (`DomainSearch::rdapServers()`), reading only the registrar entity's name, the registration /
  expiration / last changed events, statuses, nameservers and `secureDNS`; a TLD without RDAP gets its WHOIS server
  from `whois.iana.org` (port 43, kept a day) and its text is shown as is (`Whois::queryUsing()` in tests). The domain
  search's captcha session (`domain_search.verified_until`, partial `filament.client.captcha-box`) and
  `Whois::PER_MINUTE` per client or address.
  Public area (Settings → Public area, `PublicAreaSettings`, `gaiacp.public_area.*`, `App\Support\PublicArea`): the
  client panel's pages for visitors, each off until switched on. A visitor gets the side menu `PublicArea::navigation()`
  (the panel's `navigation()` closure; signed-in clients keep the pages' own until a menu is saved) and `filament.client.public-topbar`
  (language from `Locales::available()` via `/language/{locale}` into the session, read by `SetLocale`; the cart
  while the public order page has items; Log in). The site root is `PublicHome` (route `filament.client.home`, in
  place of Filament's redirect): the welcome title / Markdown text per language, the domain search box and
  `OrderCatalogue::groups()` with `OrderForm::fromPrice()`, Order to `/cart/<group>?product=` (else sign up / login);
  without `public_area.home` it sends visitors to the login and always sends clients to `/dashboard`. Pages open to
  visitors use `Client/Concerns/OpenToVisitors` (no `Authenticate`, email confirmation or 2FA on their route; their
  `canAccess()` decides): `SearchDomains` (visitors while `public_area.domain_search`; Add to cart only while the
  public order page is on, transfer opens `/cart` on it) and `DomainPrices` (`/domain-prices`, each active TLD's
  shortest register / transfer / renew price, also for clients, linked from the search). `ScopedToClient` gives a
  visitor no records and no global search. Login, sign up and reset show `filament.client.public-back` while Home is on.
  PublicOrder is a full page now, so it has the same side menu.
  Support for visitors: the knowledge base (`public_area.knowledge_base`; `KbArticle::shown()` leaves out
  `clients_only` articles for visitors, whose article page sends them to the login with `url.intended`) and Contact us
  (`public_area.contact`, `Client/Pages/ContactSupport` at `/contact`: name, email, a client-visible department,
  subject with KB suggestions, a honeypot `website` field, the `contact_form` captcha and `PER_HOUR` per IP). With
  `public_area.contact_details` (`PublicArea::contactDetails()`) the page also shows the Settings → Company lines in
  `contact_fields`, `contact_hours` / `contact_text` per language and a Google Maps search link (`contact_map`), with or
  without the form; clients then get the page in their Support menu instead of the redirect to a new ticket. It
  calls `Tickets::openFromVisitor()`: a ticket without a client (never matched to one by address) with a secret
  `access_token` (hidden from arrays; tickets from email senders have none and keep answering by email). `Ticket::publicUrl()` is `Client/Pages/VisitorTicket`
  (`/support/ticket/{token}`: messages without notes, Reply via `replyFromVisitor()`, Close) and its files at
  `support/ticket/{token}/files/{attachment}` (`VisitorTicketFileController`); `ClientMailer` puts that link with
  "View your ticket" in the ticket emails of a ticket without a client. Linking a client ends the link (404).
  Announcements (`App\Models\Announcement`, admin `AnnouncementResource` under Support, `AdminArea::Support`): texts per
  locale (title + Markdown, rendered with HTML escaped), a unique `slug` from the default title, `published_at` (null =
  draft; `published()` = due) and `clients_only`; `shown()` is what the current viewer may read. Client pages
  `Announcements` (`/announcements`, 10 a page; for visitors while `public_area.announcements`) and `AnnouncementPage`
  (`/announcements/{slug}`; a visitor on one kept for clients goes to the login with `url.intended`); the RSS feed
  `AnnouncementFeedController` (`/announcements/feed`, route `filament.client.announcements.feed`, 404 while off, the
  20 newest public ones); `PublicHome::news()` puts the newest three on the home page.
  Pages of your own (`App\Models\CustomPage`, admin `CustomPageResource` under Support → Pages, `AdminArea::Support`):
  texts per locale (title, HTML from Filament's `RichEditor`, description), `slug` like announcements, `published`,
  `clients_only`. `CustomPage::html()` renders with `RichContentRenderer::toHtml()` (Filament's HTML sanitizer, so no
  script or `javascript:` link survives). Client page `CustomPageView` (`/pages/{slug}`, visitors and clients; a draft is
  404, a visitor on one kept for clients goes to the login with `url.intended`; `metaDescription()`, `noindex()` for
  clients only). Pictures go through `App\Support\PageImages` (a Filament `FileAttachmentProvider` for the renderer,
  closures on the editor): public disk `pages/<random>.<ext>`, PNG / JPEG / GIF / WebP up to 5 MB, served by
  `PageImageController` (route `page-image`, `/page-images/{file}`, no `storage:link`); unused images are not deleted.
  Menus add them as `custom_page` rows (`ClientMenu`); the sitemap lists the published public ones.
  Server status (`public_area.status`, `App\Support\StatusPage`, client page `ServerStatus` at `/status`, visitors and
  clients): active servers with a `monitor_module` and `servers.on_status_page`, named `public_name` else `name` (never
  hostname or IP), with `monitor_status` and `UptimeHistory` 30d / 24h (`filament.client.server-uptime`); the down
  ones first, then maintenance, and a banner with `ServerIncidents::message()` while one has a problem.
  Cookie banner (Settings → Cookies, super admins; `gaiacp.cookies.*`, `App\Support\CookieConsent`): the client
  panel's `BODY_END` hook renders `filament.client.cookie-banner` (not while an admin views as a client). The choice is
  a plain `gaiacp_consent` cookie (`all` / `necessary`, 180 days) written and read by the banner's script only; the
  scripts wait in a `<template>` and are re-created as `<script>` elements on Accept all. Without scripts it is one OK.
  Footer "Cookie settings" reopens it; withdrawing reloads the page; Help mentions it (`<!-- if:cookies -->`)
  A Google Analytics measurement ID (`cookies.google_analytics`, `G-…`) adds the gtag script to those scripts
  (`CookieConsent::scripts()`), so it too runs only after Accept all.
  Search engines (Settings → Client area, `gaiacp.seo.*`, `App\Support\Seo`): `filament.seo` on `HEAD_END` of both
  panels; /admin and signed-in pages are always `noindex`, the public pages (login, sign up, order page) are indexed
  unless `seo.index` is off, with `seo.description` as meta and Open Graph description (company name and logo too).
  A visitor's language is in the address too (`?lang=`, read by `SetLocale` and kept in the session; the top bar's
  language route drops it on the way back): indexable pages get a canonical link (path, `page` and `lang` only) and
  hreflang alternates per language plus `x-default`, og:title is the page title. `SitemapController` serves
  `/sitemap.xml` (the public pages switched on, KB articles, announcements and pages of your own not kept for clients, each language) and
  `/robots.txt` (Disallow /admin/ and /install/ + Sitemap, or Disallow / while not indexable); `public/robots.txt` is
  gone and the updater leaves an unchanged old copy behind (`Updater::DROPPED`).
  Terms of service (Settings → Client area, `gaiacp.client_area.terms*`, `App\Support\TermsOfService`): link and / or
  Markdown text, `checkbox()` on Order and Register; `ClientOrder` / `ClientSignup` refuse without it and keep
  `terms_accepted_at` / `terms_version` on the order / client (version = the date and time the terms changed unless typed)
  Client emails (`MailTemplate::linksToClientArea()`, not the login ones) end with a "View in the client area" button
  (`App\Support\ClientAreaLinks`: the notice, the visible service / domain, else Home; none without a login or with
  `gaiacp.client_area.email_links` off; `ClientMailer::send(invoice:)`). Help (`Client/Pages/Help`) renders
  `resources/docs/client-area/<locale>.md`, keeping `<!-- if:key -->` parts only while that feature is on
  (`Help::features()`): a client feature changes that guide in both languages
  The admin panel's System → Help (`App\Filament\Pages\Help`, open to every admin like the dashboard) renders the admin
  guide `docs/admin-guide/en/NN-slug.md` (the `<locale>/` file of the same name when there is one), rewriting links
  between chapters to `?chapter=slug` and links to other docs to GitHub (`gaiacp.updates.repository`); `?q=` searches
  its `## ` sections with `KbArticle::searchable()`
  Maintenance mode (Settings → Client area; `gaiacp.client_area.maintenance` / `_message` / `_redirect`):
  `App\Http\Middleware\ClientAreaMaintenance` (client panel, persistent) answers every client page with
  `resources/views/client-area/maintenance.blade.php` (503) or redirects off-site; active admins signed in to /admin
  (`App\Support\ClientAreaMaintenance::lets()`) pass with a banner. Webhooks and /admin are outside the panel
  Sign up (`Client/Pages/Auth/Register`, `/signup`, 404 and no login link while `gaiacp.client_area.signup` is off, the
  default) → `App\Actions\ClientSignup` (client + client user, refuses an address any client or user has, logs
  `client.signed_up`, `NewClientMail` to admins with Clients access, Settings → Automation → New client email). The client
  panel requires email verification: `User` is `MustVerifyEmail`, but `hasVerifiedEmail()` is true for every non-client role
  and logins made by `ClientAreaAccess` are created verified, so only self-signups confirm, through the
  `email_verification` template sent at once (`User::sendEmailVerificationNotification()`, also from
  `Client/Pages/Auth/EmailVerificationPrompt`). A sign-in provider puts its confirmed address in `ClientSignup::continueWith()`
  (session); Register then fixes the email from the session and asks no password. Sign-in plugins add
  `Plugin::clientRoutes()` (guest routes `filament.client.*`) and `clientLoginButtons()` (under the login and sign up
  forms), and for /admin `adminRoutes()` / `adminLoginButtons()`: `plugins/GoogleLogin` (`GoogleOAuth` code flow +
  userinfo, one redirect URI per panel, `GoogleSignIn`: login by email with that address, `ClientAreaAccess::connect()`
  for a client without a login, else on to sign up; refuses admins who aren't clients and inactive logins;
  `handleAdmin()` only an existing active admin, while `gaiacp.google_login.admins`), Settings → Google sign-in
  (`gaiacp.google_login.*`, secret encrypted)
- Other ways in (`App\SignIn`, Settings → Login security → `gaiacp.sign_in.*`), on both login pages through
  `Filament\Concerns\QuickSignIn` (view `filament.sign-in.options` under the form): passkeys (`SignIn\Passkeys\Passkeys`,
  WebAuthn by hand: `Cbor`, `CoseKey` (ES256, RS256) to PEM, `openssl_verify`; `passkeys` table, public keys only;
  discoverable, user verification required, attestation none, rpId = request host, one-time challenge in the session;
  added from both profiles via `Concerns\ManagesPasskeys`, not while viewing as a client; a passkey skips 2FA) and
  "Email me a sign-in link" (`SignIn\EmailLinks`: signed `filament.{panel}.sign-in-link` for 15 minutes, nonce used once
  in the cache, 3 per address / 10 per IP in 15 minutes, `App\Notifications\SignInLink`). Google and a link only prove
  the email: `SignIn::begin()` keeps a pending sign-in in the session (tied to the login's email and password hash), the
  login page then asks the 2FA code (Filament's challenge form) or, for a link, a press of Log in (mail scanners open
  links). `SignIn::complete()` logs in, logs `sign_in.<method>` and sets the `gaiacp_last_sign_in_<panel>` cookie that
  marks "Last used" (password logins set it too)
- Captcha: Settings → General (`GeneralSettings`, super admins) picks `gaiacp.captcha.provider` (a `App\Captcha\CaptchaModule`
  key: captcha plugins `plugins/Recaptcha` (v2 checkbox), `plugins/Turnstile`, each with its keys page) and
  `gaiacp.captcha.forms` (`Captcha::FORMS`). `App\Captcha\Captcha::components($form)` adds the field
  (`resources/views/filament/forms/captcha.blade.php`, explicit render, token in its state, not dehydrated) to the
  client Login / Register / RequestPasswordReset and the admin Login / RequestPasswordReset through
  `Concerns\HasCaptcha`; a verified token is kept in the session 5 minutes (the 2FA step validates again), and a
  ValidationException forgets it and dispatches `captcha-reset`. A new captcha = a plugin implementing `CaptchaModule`
- Users: `users.role` (`App\Enums\UserRole`: `admin`, `client`) and `users.active`; `User::canAccessPanel()` lets
  active admins into `/admin` and active client users (`users.client_id`, `Client::user()`) into the client panel only.
  Admins are managed under Settings → Admin users (`Resources/Users`, `User::admins()` scope; you can't deactivate or
  delete yourself, admins with activity log entries are only deactivated). Client logins come from the client page's
  "Client area" menu (`ClientAreaActions` → `App\Actions\ClientAreaAccess`: invite = create the user + email
  `client_area_invite` with a set-password link, turn login off / on, log in as client on the `client` guard with a "viewing as" bar and Back to admin
  (`VIEWING_AS` in the session; a client not invited yet gets a login kept turned off, which opens only for the admin
  and is turned on by an invitation), all
  logged; the user's name and email follow the client's. New client takes an optional client area password
  (`ClientAreaAccess::createLogin()`, emailed with `client_area_login` when "Email the login details" is ticked, in place
  of the signup email). An admin user with the client's email is linked instead (`users.client_id` on the admin, role
  unchanged): one login opens both panels, their password is never changed or emailed, and Turn off login unlinks them
- Client devices and sign-ins (`App\ClientSessions\ClientSessions`): `TrackClientSession` (client panel, persistent,
  before Authenticate) gives each signed-in browser a random token in its session and a `client_sessions` row with its
  hash; the first request after a sign-in also writes a `client_logins` line, and `TrackClientSignIns` adds failed tries
  with a client login's email and drops the row on Logout. Deleting a row signs that browser out on its next request
  (client page Devices and sign-ins in the user menu: Log out / Log out other devices; admin client page Sign-ins tab:
  Log out everywhere). "Log in as client" leaves no trace there. `gaiacp:prune-logs` deletes rows idle longer than
  `session.lifetime` and sign-ins older than `ClientSessions::KEEP_DAYS` (180)
- Client Email history (`App\Filament\Client\Resources\Emails\EmailResource`, user menu, Settings → Client area →
  Email history): the client's `email_logs` rows with `to_client` (set by `LogSentEmail` for `ClientMail` /
  `ClientMessageMail` that went to the client's address, not the admins' emails about them) and status sent, kept as
  long as Settings → Logging keeps sent emails; no Bcc shown, and `MailTemplate::holdsSecrets()` ones (login details,
  invitation, email confirmation, hosting welcome) listed without their text. Services and Domains lists have
  "Order a new service" / "Order a domain" (`Order::callToAction()`), in the empty state when there are none
- Access levels: `users.super_admin` opens everything; other admins open only the `App\Enums\AdminArea` values in
  `users.admin_areas` (`User::canUseArea()`; Sales = leads and quotes, for a salesperson without payments or servers). Every Filament page and resource uses `App\Filament\Concerns\LimitedToAdminArea`
  and names its area in `adminArea()` (none = super admins only: Admin users, Servers, Registrars, System settings,
  Plugins); a class with its own `canAccess()` calls `canUseAdminArea()`. `AdminAccessTest` fails on a page without
  it. The first admin created is a super admin; nobody changes their own access
- Admin 2FA is Filament's app authentication (TOTP + recovery codes, `users.app_authentication_*`, encrypted),
  optional per user: each admin turns it on at `/admin/profile` (user menu)
  (clients at their client-area profile). Until it is on, `App\Livewire\TwoFactorReminder` (`CONTENT_START` hook of both
  panels) suggests it; Not now sets `users.two_factor_reminder_dismissed_at` and it never shows again
- "Forgot password?" on the login screen is Filament's password reset (`Pages/Auth/RequestPasswordReset`: the same answer
  for any address). The email is `App\Notifications\ResetAdminPassword` (`App\Mail\PasswordResetMail`: `<x-client-mail>` with the company logo
  from the client area, `<x-admin-mail>` from /admin), sent at once (no queue worker) in the admin's
  `users.locale` (`User::preferredLocale()`); only active admins get one. Logged as `user.password_reset_requested` / `user.password_reset`
- Login security: `App\Support\LoginSecurity` (`gaiacp.login_security`, Settings → Login security, super admins) counts
  wrong passwords / 2FA codes per IP (`recordFailure()`, from `Concerns\BlocksBannedIps::countingFailedLogins()` on both
  Login pages; captcha and empty fields don't count) and after `max_attempts` within `ban_minutes` writes an `ip_bans`
  row (`IpBan`, logged `ip.banned`). `App\Http\Middleware\BlockBannedIps` (both panels) answers a banned guest with
  `resources/views/auth/ip-banned.blade.php` (403), signed-in users pass; the pages' Livewire submits check again.
  `allowed_ips` (IPs / CIDR) are never banned. Settings → Banned IPs (`IpBanResource`) lifts or adds bans;
  `gaiacp:prune-logs` deletes bans that ended 30 days ago. Filament's own limits (5 logins, 2 reset emails a minute) stay
- Proxies: `App\Http\Middleware\TrustProxies` (replaces Laravel's) trusts `App\Support\Cloudflare::IP_RANGES` unless
  `gaiacp.proxies.cloudflare` is off, plus `gaiacp.proxies.trusted`, so `$request->ip()` is the visitor behind Cloudflare;
  System health warns when Cloudflare is in front but untrusted or on Flexible SSL
- Branding: Settings → Company's logo, dark mode logo and tab icon (`company.logo` / `logo_dark` / `favicon`, public disk
  `branding/`) through `App\Support\Branding`, served by `BrandingController` (route `branding`, no `storage:link`
  needed): the client panel's `brandLogo()` / `favicon()`, the payment notice and `MailLayout`. Without a logo the name
  shows. /admin keeps the GaiaCP brand (project rule): its logo, dark logo and tab icon are `public/images/gaiacp/` (`logo.svg`,
  `logo-dark.svg`, `mark.svg`, also in `filament/gaiacp-mark` = "Powered by").
  SVG uploads go through `App\Support\SvgLogo`: cleaned (enshrined/svg-sanitize, no scripts or outside references,
  served with a sandboxing CSP), must give a viewBox or px width / height (root size set from it), and emails get a
  PNG copy (`<name>.email.png`) made with Imagick, or without it drawn by the admin's browser on Settings → Company (`settings.email-logo`, `SvgLogo::storePng()`; System health → Logo in emails warns until it exists)
- Themes: `App\Themes\ThemeModule` (from a plugin, `Plugin::themeModules()`: colours, Bunny font, serif font, a
  stylesheet view, colours for pages outside Filament) and `App\Themes\Themes`. `current()` is, in the admin panel,
  `users.theme` from Profile while its plugin is on, else `gaiacp.theme.default` from Settings → General, else the first
  one on, else Filament's look; in the client panel `forClientArea()`: `gaiacp.client_area.theme` (Settings → Client
  area), else the same fallbacks. In the client panel `colors()` swaps primary for Settings → Client area → Brand
  colour (`gaiacp.client_area.brand_color`, `Themes::brandColor()`), and `filament/themes/client` /
  `themes/standalone` point the theme's accent (`--theme-accent`, `--gaia-accent`, `--accent`) at it. `ApplyTheme` (persistent middleware in both panels) registers its colours after the
  session; each panel's `font()` / `serifFont()` closures and `STYLES_AFTER` hook read it at render. The client panel
  also always loads `filament/themes/client.blade.php` (Home, payment notice, credit, help, banners, /cart and Order's
  product cards and summary; `var(--theme-*, Filament's colour)`, so it works with no theme), and Home / Credit use the
  admin dashboard's `.gx-*` cards (`filament/dashboard/styles`); `App\Support\Greeting` is both dashboards' heading. The
  maintenance and IP-ban pages @include `themes/standalone` (the client-area theme's font and
  `themeStandaloneColors()`). `plugins/GaiaTheme` (default; plain CSS over Filament's classes in
  `views/styles.blade.php`, no build step) and `plugins/FlatTheme` (Filament's emerald / grey / Inter, no shadows or motion). Both @include the shared layout
  `resources/views/filament/themes/layout.blade.php` (shell, grouped menu, the admin's card, cards; set by `--theme-*`
  variables; in both panels, the admin-only rules match only admin markup). `themeUserMenuPosition()` = top bar or
  sidebar foot (both themes: sidebar, in both panels). The client menu, like the admin's: Home and Order, then Services
  (Hosting and Other services = the client `ServiceResource::getNavigationItems()` with `?kind=`, Domains between),
  Billing (payment notices, payments, credit) and Support (Tickets, Knowledge base, Help).
  The admin sidebar groups are Main, Billing, Services, System (`AdminPanelProvider::navigationGroups()`, in that
  order; each sidebar page / resource sets `$navigationGroup`, `App\Filament\Pages\Dashboard` included); avatars are
  `App\Filament\Avatars\InitialsAvatar` (an SVG data URL, nothing loaded from another site). The dashboard
  (`App\Filament\Pages\Dashboard`: greeting, every card half the width) has `App\Filament\Widgets\` BillingOverview (open balance),
  ActivityChart (orders, completed orders and income per day / month, plain SVG), UpcomingRenewals,
  ServerCapacity (accounts vs max) and NeedsAttention (cron stopped, pending orders, change requests, overdue notices, failures of the
  last `FAILURE_DAYS`, each only for admins with that area); their CSS is `resources/views/filament/dashboard/styles.blade.php`
  in Filament's colours, so it works with any theme or none. The cards are laid out by
  `resources/views/filament/dashboard/cards.blade.php`: two `.gx-col` columns of the same width from 768 px at any
  width, each filling down on its own (masonry), stacked on a phone (first column first). Each card sits in a `.gx-slot`
  with a handle (`.gx-grip`, Filament's `x-sortable` = SortableJS with one shared group, arrow keys too, left / right
  change column) that calls `Dashboard::reorderDashboard([1 => keys, 2 => keys])` (no re-render). Show / hide cards
  (header action) only shows / hides cards. Both are kept per admin in `users.dashboard_layout`
  [{widget, column, visible}], read by `App\Support\DashboardLayout` (default columns: `SECOND_COLUMN`; unknown cards
  dropped, new ones appended to the column with fewer cards, cards the admin may not open never offered; a reorder
  keeps hidden cards in their places); widgets `use Widgets\Concerns\FitsDashboard` (a `dashboardLabel()`, full width
  of their slot). The top bar: the logo over
  the sidebar, then the search (`mod+k`, placeholder in `lang/vendor/filament-panels/<code>/global-search.php`) and
  `resources/views/filament/topbar-actions.blade.php` (`GLOBAL_SEARCH_AFTER`: Client area in a new tab, New order for
  admins with Clients access; icons only on a phone) on the inset area, then the bell: Filament database notifications (`notifications`
  table, written at once with `notifyNow`, no queue) made by `App\Notifications\AdminAlerts` from the activity log
  events in its `EVENTS` (`ActivityLog::booted()`), each in the admin's language, for active admins with
  `users.panel_notifications` on (Profile / Admin users) who `canUseArea()` it, never the admin who caused it; a payment
  only when no admin recorded it, a new payment notice only when it is not an order's own (New order says that). `App\Reminders\ExpiringDomainAlerts` (in `gaiacp:send-reminders`) logs
  `domain.expiring` once per expiry date. A new kind of notification = an event in `EVENTS` and its text there.
  The client area has its own bell (`App\Notifications\ClientAlerts`, same hook): events about a client's notices
  (`invoice.created`, `invoice.reminder_sent`, `invoice.paid`), services (set up, suspended, ended, request answers,
  price change, usage), domains (`reminder.sent`, renewed) and tickets (answered, closed) go to the client's login in
  its language, unless it caused them, the service is not client-visible, the login turned it off (the same
  `users.panel_notifications`, client profile) or Settings → Client area → Notifications bell is off; no push. They are
  stored as `App\Notifications\ClientAlert`, and both panels use `App\Livewire\PanelNotifications`, which lists only
  those in the client area and all others in /admin, so an admin linked to a client keeps the two apart. Hidden while
  an admin looks through the client's login. On a touch screen a notification is swiped away left or right
  (`resources/views/filament/notification-swipe.blade.php`, both panels: calls the notification's own `close()`, which
  deletes it from the bell's list and closes a pop-up).
  Each one also goes as a push to the admin's devices (`App\Push\WebPush::notify()`, after the commit and the
  response): Web Push with no library, VAPID ES256 and aes128gcm by hand with OpenSSL, the VAPID key pair made on
  first use in `settings` (`push.vapid`, encrypted; a new APP_KEY makes new keys and drops the devices). Devices are
  `push_subscriptions` (keys encrypted), added from Profile → Push notifications (`EditProfile::savePushSubscription()`,
  only the browsers' push services in `WebPush::PUSH_HOSTS`, so never an address an admin made up), removed there or
  when the push service answers 404 / 410; calls go to the module log as `push`. The panel is installable (PWA):
  `PwaController` serves `/admin/manifest.webmanifest` and the service worker `/admin/sw.js` (scope `/admin/`, shows the
  push and opens its page), `resources/views/filament/pwa.blade.php` links them; icons in `public/images/gaiacp/`
- Region (Settings → General, `App\Support\Region`, keys `region.*`): the timezone (`Settings::put()` sets
  `app.timezone` and PHP's default, so due dates and the daily run follow it), the date format (`AppServiceProvider::dateFormat()`,
  one of `Region::DATE_FORMATS`; tables and date pickers use it) and the country new clients start in (`Client` fills it
  when blank; `EppPhone` reads a number without a code as the client's country's). The installer asks them with the base
  currency (`Installer::saveRegion()`, which changes `GAIACP_CURRENCY` only while nothing is priced). New installs start in
  UTC with no country and no tax rule; migration `2026_10_24_000300_pin_region_settings` kept existing installs on
  Europe/Athens, `d/m/Y`, GR and the 17% rule
- Languages: `App\Support\Locales` over the languages `App\Support\Translations` finds (English, every `lang/<code>.json`,
  uploads in `storage/app/private/lang`), as Settings → Languages switches them (`languages.enabled` / `languages.default`;
  a `gaiacp.locales` list fixes them, as tests do). Uploads (`Translations::import()`) go to that storage folder, a plugin's
  texts to `plugins/<Folder>/<code>.json` there, loaded over the shipped files (`PluginManager::registerTranslations()`);
  completeness from `TranslationKeys`, cached per GaiaCP + plugin versions. The default is not
  `app.locale`, which follows the language in use). `users.locale` = panel language (Profile, `SetLocale` middleware),
  `clients.locale` = email language (`Client::preferredLocale()`; `ClientMail` renders in it). Pickers show only with
  2+ languages. `gaiacp:translations <code> [--write]` lists / adds the texts missing from `lang/<code>.json`. Filament's texts its own Greek files lack go in `lang/vendor/<namespace>/el/<file>.php` (merged over
  them; `LocalesTest` fails on any package text without Greek, and on Laravel's auth / passwords / pagination)

## Public demo

`GAIACP_DEMO=true` (`App\Support\Demo`, full description in `docs/demo-site.md`): `Demo::boot()` sends email to the
log, makes every HTTP call fail through a global HTTP client middleware and keeps the demo accounts' login columns;
`LimitedToAdminArea` closes `Demo::CLOSED`, `PluginManager` keeps `Demo::PLUGINS_OFF` off (`PluginState::OffInDemo`)
and loads `plugins/Demo` (panel, registrar, monitoring on GaiaCP's own records) only here; both login pages are
filled in and show `filament.demo.login`, every page `filament.demo.bar`. `App\Support\DemoSnapshot` builds
`DemoSeeder` into a SQLite file in a child process (`GAIACP_DEMO_BUILD`, read in `AppServiceProvider::register()`)
and copies it into the live SQLite database in one transaction, keeping `sessions`.
