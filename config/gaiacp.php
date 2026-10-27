<?php

// Every value below except currency can be changed on the Settings page (admin user menu);
// a saved value there wins over .env. See App\Support\Settings.
return [

    // The business sending the reminders: name and email become the mail sender,
    // the rest is printed in the reminder signature.
    'company' => [
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'GaiaCP')),
        'email' => env('MAIL_FROM_ADDRESS'),
        'phone' => null,
        'website' => null,
        'address' => null,
        'vat_number' => null,
        'tax_office' => null,
        // Files on the public disk (App\Support\Branding): the logo in the panels, payment notices and client
        // emails (company name without one), an optional one for dark mode, and the browser tab icon
        'logo' => null,
        'logo_dark' => null,
        'favicon' => null,
    ],

    // Languages the panel and client emails can use (Settings → Languages, App\Support\Locales): English and every
    // lang/<code>.json, plus the ones uploaded there (storage/app/private/lang). `enabled` null = all of them; the
    // default is the language of admins and clients without one of their own. With one language the pickers stay
    // hidden. A list here (code => name, the default first) fixes them instead.
    'languages' => [
        'enabled' => null,
        'default' => 'en',
    ],
    'locales' => null,

    // The business's region (Settings → General → Region, asked by the installer): the timezone of due dates and
    // the daily run, how dates are written, and the country new clients start in (ISO code; none = picked each time).
    'region' => [
        'timezone' => env('APP_TIMEZONE', 'UTC'),
        'date_format' => env('GAIACP_DATE_FORMAT', 'd/m/Y'),
        'country' => env('GAIACP_COUNTRY'),
    ],

    // The base currency: prices are entered in it and totals over several currencies are shown in it. Other
    // currencies and their rates are under Settings → Currencies; set this before the first install only.
    'currency' => env('GAIACP_CURRENCY', 'EUR'),

    // Payment notices (proformas, not legal invoices): one per client and due date, created this many
    // days before it and emailed then. Reminders and overdue notices below are about these notices.
    'invoices' => [
        'days_before' => 14,
    ],

    // Client credit (Settings → Client credit): a balance clients top up with an "Add funds" payment notice and that
    // pays their payment notices. Amounts are in the base currency (converted for clients billed in another one).
    'credit' => [
        'enabled' => false,
        'min_deposit' => 10,
        'max_deposit' => 300,
        'max_balance' => 600,
        'auto_apply' => true, // pay unpaid payment notices from the balance in the daily run and after a deposit
    ],

    // Tax added to (exclusive) or contained in (inclusive) the prices on payment notices. A rule with a
    // country (ISO code) beats one for every country (country null). Clients can be tax exempt.
    'tax' => [
        'enabled' => false,
        'inclusive' => false,
        'rules' => [], // e.g. [['name' => 'VAT', 'rate' => 20, 'country' => null]], set under Settings → Tax
    ],

    'reminders' => [
        // Days before the due date to email the client. When the scheduler
        // missed a day, only the most urgent unsent reminder goes out.
        'before_days' => [14, 7, 1],

        // Days after the due date to send an overdue notice.
        'overdue_days' => [1, 7, 14],

        // Time of day the daily job runs (in the region's timezone, Settings → General).
        'send_at' => env('GAIACP_REMINDERS_AT', '09:00'),

        // Optional address that gets a BCC of every reminder.
        'bcc' => env('GAIACP_REMINDERS_BCC'),
    ],

    // Support tickets (Settings → Support; App\Actions\Tickets). Files are kept on the private "local" disk.
    'support' => [
        'client_tickets' => true, // clients open and answer tickets in the client area
        'client_priority' => true, // clients choose the priority (else Medium)
        'admin_emails' => true, // email the admins (Receive system emails) about new tickets and client replies
        'auto_close_days' => 7, // close a ticket that waits on the client this many days (null = never)
        'attachments_max_kb' => 5120,
        'attachment_types' => 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip',
        'reply_to' => null, // Reply-To of ticket emails to clients (a department's own email wins), e.g. the Email to ticket mailbox
        'knowledge_base' => true, // the client area's Knowledge base page (once an article is published) and article tips on new tickets
    ],

    // Leads (Clients → Leads) and quotes (Billing → Quotes); Settings → Sales
    'sales' => [
        'lead_sources' => null, // where leads come from, offered on each one (null = Phone, Email, Website, Referral, Social media, Other)
        'quote_valid_days' => 30, // a new quote is valid this many days
        'quote_footer' => null, // the text under a new quote's lines (e.g. what the prices include)
    ],

    'automation' => [
        // Suspend hosting accounts this many days after the due date (null = never).
        // Runs daily with the reminders; recording the payment unsuspends them.
        'suspend_after_days' => env('GAIACP_SUSPEND_AFTER_DAYS') !== null && env('GAIACP_SUSPEND_AFTER_DAYS') !== ''
            ? (int) env('GAIACP_SUSPEND_AFTER_DAYS')
            : null,

        // Cancel a pending order (its items and payment notice) once it is this many days old with nothing paid or set
        // up; empty = never. Runs daily with the reminders.
        'cancel_unpaid_orders_days' => env('GAIACP_CANCEL_UNPAID_ORDERS_DAYS') !== null && env('GAIACP_CANCEL_UNPAID_ORDERS_DAYS') !== ''
            ? (int) env('GAIACP_CANCEL_UNPAID_ORDERS_DAYS')
            : null,

        // Days of notice a price change (Change price) gives at least: its start date can't be sooner.
        'price_change_notice_days' => (int) env('GAIACP_PRICE_CHANGE_NOTICE_DAYS', 30),

        // Warn a hosting client (and ring the bell) once a month when disk space or bandwidth reaches this % of the
        // package's limit (gaiacp:check-usage, daily at 06:00); empty = off.
        'usage_warning_percent' => env('GAIACP_USAGE_WARNING_PERCENT') === '' ? null : (int) env('GAIACP_USAGE_WARNING_PERCENT', 85),

        // Emails of messages to clients sent each minute (gaiacp:send-client-messages), kind to the mail server.
        'client_messages_per_minute' => (int) env('GAIACP_CLIENT_MESSAGES_PER_MINUTE', 30),

        // Email the admins who receive system emails a summary after the daily run.
        'daily_summary' => (bool) env('GAIACP_DAILY_SUMMARY', true),
        // Email the admins when a client asks to change their details from the client area
        'change_request_email' => (bool) env('GAIACP_CHANGE_REQUEST_EMAIL', true),
        // Email the admins when a client asks to cancel a service from the client area
        'cancellation_request_email' => (bool) env('GAIACP_CANCELLATION_REQUEST_EMAIL', true),
        // Email the admins when a client asks to change a service's package from the client area
        'upgrade_request_email' => (bool) env('GAIACP_UPGRADE_REQUEST_EMAIL', true),
        // Compare every server's accounts with GaiaCP each night (04:30) and ring the bell when something new differs
        'compare_servers' => (bool) env('GAIACP_COMPARE_SERVERS', true),
        // Read every currency's rate from the European Central Bank once a day (Settings → Currencies)
        'exchange_rates' => (bool) env('GAIACP_EXCHANGE_RATES', false),
        // Email the admins when a client places an order in the client area
        'new_order_email' => (bool) env('GAIACP_NEW_ORDER_EMAIL', true),
        // Email the admins when someone signs up in the client area
        'new_client_email' => (bool) env('GAIACP_NEW_CLIENT_EMAIL', true),
    ],

    'domains' => [
        // Nameservers for a new registration when the domain has none of its own.
        'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('GAIACP_DEFAULT_NAMESERVERS', ''))))),

        // New domains renew automatically: they get payment notices and renewal emails. Off: they end
        // at their due date (the service's "Auto-terminate at the end of the cycle").
        'auto_renew' => true,

        // Recording a payment for a domain renews it at the registrar (unless its expiry already covers it).
        'renew_on_payment' => true,

        // A free domain (price 0) is renewed on its own by the daily run; with this on, only while the
        // client has an active hosting service for it (same domain or same order).
        'free_renew_requires_product' => true,

        // Internationalized domain names (Greek letters etc.), stored as typed and sent out in punycode.
        'idn' => false,

        // Late renewal fees from each TLD's grace and redemption prices (Settings → Registrars),
        // added to unpaid payment notices once the domain has expired.
        'late_fees' => false,

        // An SSL certificate product (id) offered with a domain ordered on its own, at ssl_discount percent off its
        // first period (App\Support\DomainSsl); null = none.
        'ssl_product' => null,
        'ssl_discount' => 0,
    ],

    // The captcha on the login, sign up and forgot password forms (Settings → General): a captcha plugin's key
    // (recaptcha, turnstile; null = none) and the App\Captcha\Captcha::FORMS it shows on.
    'captcha' => [
        'provider' => null,
        'forms' => ['client_login', 'client_signup', 'client_password_reset', 'domain_search', 'contact_form'],
    ],

    // The admin panel's theme (Settings → General) for admins who haven't picked their own in Profile: a theme
    // plugin's key (gaia, flat). App\Themes\Themes falls back to the first theme that is on.
    'theme' => [
        'default' => 'gaia',
    ],

    // Settings → Login security: after max_attempts wrong passwords or two-factor codes from one IP address within
    // ban_minutes, that address can't open the login, sign up or forgot password pages for ban_minutes (like WHMCS).
    // allowed_ips (addresses or CIDR ranges, one per line) are never banned. App\Support\LoginSecurity.
    'login_security' => [
        'enabled' => true,
        'max_attempts' => 3,
        'ban_minutes' => 15,
        'allowed_ips' => null,
    ],

    // What clients can do themselves in the client area (Settings → Client area). Each action goes through
    // the same code as the admin's and is logged with the client's login.
    // The AI assistant (Settings → AI assistant, App\Ai\Assistant): an AI plugin (e.g. Claude) writes suggestions that
    // an admin reviews before using them. Nothing is sent until an AI plugin is on and an admin presses a button.
    'ai' => [
        'provider' => null, // the AI plugin's aiKey() when more than one is on; null = the first one on
        'features' => [
            'improve' => true, // Improve a text the admin wrote (spelling, tone, length)
            'translate' => true, // Translate a text into another language
            'domains' => false, // Suggest other names in the client area's domain search (clients and visitors press it)
            'products' => true, // Write a product's description from its details
            'outage' => true, // Write the server problem notice from what the monitors say
            'questions' => true, // Reports → Ask in plain words: read which ready-made question a question is
            'help' => true, // System → Help: answer a "how do I…" question from the admin guide
        ],
        'monthly_tokens' => null, // tokens (sent + received) all admins may use a calendar month; null = no limit
    ],

    // Cookie banner on the client area, /cart and sign up (Settings → Cookies, App\Support\CookieConsent)
    'cookies' => [
        'banner' => false,
        'text' => null, // null = the built-in text in the visitor's language
        'policy_url' => null, // a page with the cookie policy; null = no link
        'scripts' => null, // HTML (e.g. an analytics snippet) added only after "Accept all"
        'google_analytics' => null, // a GA4 measurement ID (G-…): its tag is added with the scripts, after "Accept all"
    ],

    // What search engines and link previews see of the client area (App\Support\Seo): the login, sign up and public
    // order pages may be indexed; signed-in pages and /admin never are.
    'seo' => [
        'index' => true,
        'description' => null, // the meta / link preview description; null = none
    ],

    // The public area (App\Support\PublicArea, Settings → Public area): pages visitors see without logging in, in the
    // client area's look with a side menu. Each is off until switched on; with all off the site root is the login.
    'public_area' => [
        'home' => false, // a home page at the site root for visitors: welcome text, domain search, what is sold
        'welcome_title' => [], // per language code; empty = the company name
        'welcome_text' => [], // per language code, Markdown; empty = none
        'domain_search' => false, // Search for a domain for visitors (needs a TLD with a registration price)
        'domain_prices' => false, // a page with every TLD's registration, transfer and renewal price
        'knowledge_base' => false, // the knowledge base for visitors, without the articles kept for clients
        'contact' => false, // Contact us: visitors open a ticket, read and answer it at its secret link
        // The contact page's details (Contact us, for visitors and clients): the Settings → Company lines picked here, the
        // opening hours and a text (per language code, Markdown) above the form, and an Open in maps button for the address
        'contact_details' => false,
        'contact_fields' => ['address', 'phone', 'email'],
        'contact_hours' => [],
        'contact_text' => [],
        'contact_map' => true,
        'announcements' => false, // the announcements for visitors (not those kept for clients) and their RSS feed
        'status' => false, // the servers' status page, for visitors and clients
    ],

    'client_area' => [
        'theme' => 'gaia', // the look of the client area, /cart and sign up (App\Themes\Themes::forClientArea())
        'brand_color' => null, // #rrggbb for the client area's buttons and links instead of the theme's; null = the theme's
        'domain_nameservers' => true,
        'domain_epp' => true,
        'domain_auto_renew' => true,
        'domain_contacts' => true,
        'domain_child_nameservers' => true,
        'domain_lock' => true,
        'hosting_password' => true,
        'hosting_login' => true,
        'hosting_usage' => true,
        'hosting_uptime' => true, // the server's uptime (24 hours, 30 days) on a hosting account's page, from its monitor
        'orders' => true,
        'monthly_prices' => true, // the Order pages show each cycle's price per month, with what is billed each cycle under it
        'email_links' => true,
        'notifications' => true, // the bell in the client area's top bar (App\Notifications\ClientAlerts)
        'email_history' => true, // the client's menu → Email history: the emails sent to them, from the email log
        'public_orders' => false, // /cart: visitors choose an order before they sign up or log in (needs orders on)
        'signup' => false, // a Sign up page on the client login (new clients confirm their email address first)

        // Terms of service (App\Support\TermsOfService): while on, with a link and/or a text (Markdown), the Order page
        // and Sign up ask for "I accept the Terms of Service" and the order / client keeps when and which version.
        // The version changes to the date and time by itself when the terms change, unless the admin writes one.
        'terms' => false,
        'terms_url' => null,
        'terms_text' => null,
        'terms_version' => null,

        // Maintenance mode (Settings → Client area, like WHMCS): while on, every client area page shows the message (null =
        // the built-in one) or sends the visitor to redirect when set. Signed-in admins still get in; /admin and
        // webhooks are not affected. App\Support\ClientAreaMaintenance.
        'maintenance' => false,
        'maintenance_message' => null,
        'maintenance_redirect' => null,

        // Server problem notice (App\Support\ServerIncidents): while a server's monitor says down or maintenance,
        // its clients see a notice at the top of every client area page naming their affected services, with the
        // message (null = the built-in one). Needs a monitoring plugin (Uptime Kuma, UptimeRobot, HetrixTools) linked to the server.
        'incident_notice' => true,
        'incident_message' => null,
    ],

    // Zipped SQL dump of the whole database (gaiacp:backup), kept in storage/app/private/backups
    // and copied to FTP and/or emailed. Edited under Settings → Backups.
    'backups' => [
        'enabled' => false,
        'frequency' => 'daily', // daily or weekly
        'weekday' => 1, // for weekly: 0 = Sunday … 6 = Saturday
        'time' => '03:00',
        'keep' => 7, // newest backups kept on this server and on the FTP server
        'zip_password' => null, // AES-256 zip encryption when set
        'include_logs' => false, // off: RunBackup::LOG_TABLES are written without their rows
        'email' => null, // send each backup (and failures) to this address
        'ftp' => [
            'enabled' => false,
            'protocol' => 'ftp', // ftp or ftps (explicit TLS)
            'host' => null,
            'port' => 21,
            'username' => null,
            'password' => null,
            'path' => '/backups',
            'passive' => true,
        ],
    ],

    // How client emails go out and what wraps them. With smtp off, the mailer from .env (MAIL_*) is used.
    // The password is stored encrypted. The logo is the company's (Settings → Company); header and footer are
    // Markdown with the same placeholders as the templates, shown above and below every email's text.
    'mail' => [
        'smtp' => false,
        'host' => null,
        'port' => 587,
        'encryption' => 'tls',
        'username' => null,
        'password' => null,
        'header' => null,
        'footer' => null,
    ],

    // Settings → Logging: the module log (every panel / registrar / ΑΑΔΕ request and response, secrets masked)
    // is off until switched on; the email log is always kept. Rows older than these days are deleted daily
    // (gaiacp:prune-logs), the activity log and the bell notifications too.
    'debug' => [
        'module_log' => false,
        'module_log_days' => 30,
        'email_log_days' => 90,
        'activity_log_days' => null, // null = kept for ever
        'notification_days' => 90, // bell notifications, read or not; null = kept for ever
    ],

    // Settings → System → Updates: the GitHub repository whose releases (v<version> tags with a gaiacp-<version>.zip)
    // this install updates from. A private repository needs a token that can read it (fine-grained, Contents: read).
    // Settings → Plugins: uploading a plugin zip installs PHP code that runs with GaiaCP's full access. A host can
    // turn uploads off (GAIACP_PLUGIN_UPLOADS=false); folders copied into plugins/ by hand still load.
    'plugins' => [
        'uploads' => (bool) env('GAIACP_PLUGIN_UPLOADS', true),
        'directory' => null, // null = plugins/ of GaiaCP
        'max_upload_kb' => 10240,
        // Bundled plugins that are off by default (easy.gr, ΑΑΔΕ) but should start on in a new install, e.g. "easygr,aade"
        'start_enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('GAIACP_PLUGINS_START_ENABLED', ''))))),
        // The plugin catalogue (App\Plugins\Catalogue, docs/plugin-catalogue.md): catalogue.json is trusted only when
        // catalogue.json.sig is an Ed25519 signature by one of `keys` (base64 public keys). The official key is set
        // here in the code; GAIACP_PLUGIN_CATALOGUE_KEYS adds others for a catalogue of your own (comma-separated).
        'catalogue' => [
            'enabled' => (bool) env('GAIACP_PLUGIN_CATALOGUE', true),
            'url' => env('GAIACP_PLUGIN_CATALOGUE_URL', 'https://plugins.gaiacp.digital'),
            'keys' => array_values(array_filter(array_merge([
                'uKAD0UwUilq80FNK9cQ3WSO5+R35+JI/9IbA/P0yK9M=', // official, made 2026-10-25 (gaiacp-plugins: php bin/catalogue keygen)
            ], array_map('trim', explode(',', (string) env('GAIACP_PLUGIN_CATALOGUE_KEYS', '')))))),
        ],
    ],

    'updates' => [
        'repository' => env('GAIACP_UPDATE_REPOSITORY', 'tetterip/GaiaCP'),
        'token' => env('GAIACP_UPDATE_TOKEN'),
        // gaiacp:check-for-update asks GitHub once a day and rings the bell for a new release; false turns it off
        'check' => (bool) env('GAIACP_UPDATE_CHECK', true),
        'api' => env('GAIACP_UPDATE_API', 'https://api.github.com'), // GitHub Enterprise has its own
        // A release is installed only when its gaiacp-<version>.zip.sha256 carries a .sig, an Ed25519 signature by one of
        // `keys` (base64 public keys; php bin/release-key keygen, docs/releases.md). The official key is set here in the
        // code; GAIACP_UPDATE_KEYS adds others for releases of your own (comma-separated). With no key at all, the SHA-256
        // alone is checked.
        'keys' => array_values(array_filter(array_merge([
            'uWY+PDTVEoOmd9Nw3O5FnC7+9iryo5k3JpgtwZT6poA=', // official, made 2026-10-27 (php bin/release-key keygen)
        ], array_map('trim', explode(',', (string) env('GAIACP_UPDATE_KEYS', '')))))),
    ],

    // Proxies in front of GaiaCP whose X-Forwarded-* headers are believed (App\Http\Middleware\TrustProxies), so
    // visitor IPs and https:// stay right. Cloudflare's addresses are trusted unless GAIACP_TRUST_CLOUDFLARE=false;
    // GAIACP_TRUSTED_PROXIES adds others (comma-separated IPs or ranges, e.g. a load balancer).
    'proxies' => [
        'cloudflare' => (bool) env('GAIACP_TRUST_CLOUDFLARE', true),
        'trusted' => array_values(array_filter(array_map('trim', explode(',', (string) env('GAIACP_TRUSTED_PROXIES', ''))))),
    ],

    // REST API (/api/v1, keys under Settings → API keys): calls each key may make per minute, and the most records a
    // list returns per page.
    'api' => [
        'per_minute' => (int) env('GAIACP_API_PER_MINUTE', 120),
        'max_per_page' => 100,
    ],

    // Bank transfer plugin (Settings → Bank transfer): bank details printed on payment notices and their emails.
    'payment_instructions' => env('GAIACP_PAYMENT_INSTRUCTIONS'),

    // A public demo (App\Support\Demo, docs/demo-site.md): made-up data put back every few minutes by
    // gaiacp:demo-reset, the logins filled in on both login pages, and nothing leaving the server (no email, no
    // panel / registrar / HTTP calls, no plugin uploads or updates). Never on a real install.
    'demo' => [
        'enabled' => (bool) env('GAIACP_DEMO', false),
        'reset_minutes' => (int) env('GAIACP_DEMO_RESET_MINUTES', 10),
    ],

];
