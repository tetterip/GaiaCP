# Reference: billing, servers, registrars and more

How GaiaCP's billing, server panels, registrars, SSL certificates, orders, the WHMCS import and languages work, in
detail. The [admin guide](admin-guide/README.md) covers the same ground as a walk-through.

## How billing works

- Each service has a price per cycle and a **next due date**.
- **Record payment** (on the service page or in any services table) logs the payment and moves the
  due date forward one cycle. A service suspended with reason `overdue` is reactivated once it is
  no longer overdue. The latest payment can be reversed if it was entered by mistake.
- Every day `gaiacp:send-reminders` emails each client **one** message listing their services that
  are coming up for renewal or are overdue (upcoming domains go in a separate Upcoming Domain Renewal
  Notice, and the same run sends the Expired Domain Notice when that is switched on). Each reminder is
  sent once per due date; a missed day sends only the most urgent one. Preview without sending:
  `php artisan gaiacp:send-reminders --dry-run [--date=2026-10-20]`.
- Every client has two switches, **Renewal reminders** and **Overdue notices**, to opt out of either email.
- Everything that changes a service is in the **Activity log**.

## Servers and accounts (cPanel / WHM)

1. In WHM, create an API token (*Development → Manage API Tokens*) for `root` or a reseller.
2. Add the server in GaiaCP with that username and token, then press **Test connection**: it shows the
   WHM version and the packages it found.
3. A new hosting service starts as **Pending**. **Account → Create on server** creates it with the
   service's domain and package; username (from the domain) and password are generated if left empty and
   stored encrypted on the service.
4. The **Account** menu also suspends, unsuspends, changes package, changes the password on the server and terminates (terminating asks you to
   type the username, since it deletes the account and its files).
5. With **Suspend hosting after** set in Settings, `gaiacp:suspend-overdue` (daily, with the reminders) suspends
   overdue hosting with reason `overdue`, and recording the payment unsuspends it again.
   **Do not suspend until** (Automation section of the service) exempts one account until a date.
6. **Auto-terminate at the end of the cycle** on a service stops its payment notices, and `gaiacp:terminate-scheduled`
   (daily) terminates it on its next due date.
7. Each server can also have (all optional) a **Maximum accounts**, a **Monthly cost**, its **IP address** and a
   **Status page URL**. The Servers list shows its accounts (active and suspended hosting) as "33/120 (28%)" with a
   bar, and New order and the service form show them next to each server. Its **Log in** button opens the
   panel signed in as the server's own login (WHM: `create_user_session` for `whostmgrd`; Plesk: `create_session`,
   `admin` with a secret key; HestiaCP: its login page). Income forecast and Server revenue show the profit after
   the active servers' monthly costs.
8. A hosting product lists its **Servers** (the ones that have its package). A new order, a new service and a client
   order go to the least full active one (accounts, pending ones included, against Maximum accounts; a server with no
   maximum counts as empty, a tie goes to fewer accounts) and skip full ones. New order and Complete order still let
   you pick another. When every one is full, setup stops and asks for a server.

## Plesk (service plans)

1. Add the server with type Plesk, port 8443, and either the admin login + password, or an empty login and
   a secret key (`plesk bin secret_key -c -ip-address <GaiaCP's IP>`). **Test connection** lists the
   service plans.
2. Set the service's **Package** to the service plan name (suggested from the server).
3. **Create on server** makes, for that client, one Plesk **customer** (login and password from the first
   service, found again later by its external ID `gaiacp-client-<id>`) and a **subscription** for the
   service's domain on that plan, with the service's username/password as its system (FTP) user. Later
   services of the same client go under the same customer.
4. New subscriptions use the server's first shared IPv4 address, or the IP set on the server in GaiaCP.
5. Suspend/unsuspend disables/enables the subscription; change package switches its service plan;
   terminate deletes the subscription and keeps the customer (and its other subscriptions).

## HestiaCP

1. In HestiaCP, as admin: Server settings → Configure → Security → API: allow the API (for the admin at least) and
   add GaiaCP's IP address to the allowed addresses (or allow all). Then admin → Access keys → Add: no permission
   ticked gives the key every command.
2. Add the server with type HestiaCP, port 8083, an empty username and the key as `ACCESS_KEY:SECRET_KEY`. (Or the
   username `admin` and its password, which HestiaCP still accepts.) **Test connection** lists the packages.
3. **Create on server** makes a HestiaCP **user** with the service's username, password, the client's email and
   name and the service's package, then adds the service's domain (web, DNS and mail) to it. If the domain cannot
   be added, the new user is removed again.
4. Suspend / unsuspend / terminate suspend, unsuspend and delete the user; change package and password change the
   user's (the password is both the HestiaCP login and SFTP / SSH). Usage is the user's disk, bandwidth, mail
   accounts, databases and web domains against its package.
5. HestiaCP has no one-time login link, so the client area's "Log in to HestiaCP" opens the login page.

## DirectAdmin

1. In DirectAdmin, as the admin or the reseller whose accounts GaiaCP should manage: Login Keys → Create a key that may
   use the API (the `CMD_API_*` commands), with GaiaCP's IP address allowed. (Its password works too.)
2. Add the server with type DirectAdmin, port 2222, that username and the key. **Test connection** shows how many
   accounts it owns; the Package list comes from its user packages. "IP for new accounts" is optional: empty, new
   accounts go on the login's first IP address.
3. **Create on server** makes a DirectAdmin **user** under that login with the service's username (generated ones stay
   within DirectAdmin's 10 characters), password, the client's email, the service's domain and package. DirectAdmin's
   own welcome email is off; GaiaCP sends its own.
4. Suspend / unsuspend / terminate suspend, unsuspend and delete the user; change package and password change the
   user's (the password is the DirectAdmin login, FTP and databases). Usage is the user's disk, bandwidth, mail
   accounts, databases, domains, parked domains and subdomains against its package.
5. The client area's "Log in to DirectAdmin" opens the login page (no one-time login is used).

## CyberPanel

Off until you switch it on under Settings → Plugins. Built from CyberPanel's own source; not yet tried on a real server.

1. In CyberPanel, as `admin`: Users → API Access → turn API access on for `admin`. Two-factor authentication must be
   off for it, since the API takes the password alone. CyberPanel lets only `admin` read the website list, which
   GaiaCP needs for the account state, usage and Compare with the server.
2. Add the server with type CyberPanel, port 8090, username `admin` and its password. **Test connection** shows the
   CyberPanel version; the Package list comes from its packages (named like `admin_Default`).
3. **Create on server** makes a CyberPanel **website** for the service's domain, on its package, owned by a new
   CyberPanel user with the service's username, password and the client's email. It starts on the PHP version under
   Settings → CyberPanel (PHP 8.2 unless changed), which must be installed on the server. CyberPanel finishes the
   website in the background, so it can take a minute to appear.
4. Suspend / unsuspend suspend the website; terminate deletes it, then its owner user once it owns no other website.
   Change package changes the website's package; change password changes the owner's CyberPanel login (FTP accounts,
   databases and mailboxes keep their own). Usage is the website's disk, bandwidth and databases against its package.
5. The client area's "Log in to CyberPanel" opens the login page (no one-time login is used).

## Enhance

Not tried on a real Enhance server yet: Enhance's API reference could not be reached, so the calls follow the
open-source Enhance modules for WHMCS and Paymenter. Please report what Test connection and a first order say.

1. Switch the Enhance plugin on under Settings → System → Plugins (it starts off).
2. In Enhance, Settings → Access tokens: create a token allowed to manage customers and subscriptions, and note your
   organisation's ID shown there.
3. Add the server with type Enhance, the control panel's address as hostname, port 443, the organisation ID as the
   username and the token. **Test connection** shows the organisation's name and how many plans it has; the Package
   list is the plans' names.
4. **Create on server** makes the client an Enhance **customer** (once per client and server) owned by a login with
   the client's email and the service's password, then a **subscription** to the plan with a **website** for the
   service's domain. A second service of the same client reuses the customer, and takes the first one's password.
5. Suspend / unsuspend suspend the subscription; change package changes its plan; terminate deletes its websites and
   the subscription (the customer stays). Change password changes the client's Enhance login, for all their services
   on that server. Usage shows what the subscription's resources list.
6. The client area's "Log in to Enhance" opens Enhance signed in as the customer's owner; Servers → Log in opens the
   login page.

## Proxmox VE (VPS)

1. Switch the plugin on under Settings → Plugins. In Proxmox: Datacenter → Permissions → API Tokens → Add, for
   `root@pam` (or a user with the Administrator role on `/`), with **Privilege Separation** unticked. Copy the secret:
   Proxmox shows it once.
2. Make a KVM **template** with cloud-init for each plan you sell (a cloud image, a Cloud-Init drive, the CPU, memory
   and disk of the plan, then Convert to template). Its name is the product's package.
3. Add the server with type Proxmox VE, port 8006, the token ID (`root@pam!gaiacp`) as username and the secret as API
   token. **Test connection** shows the Proxmox version; the Package list is the templates.
4. Settings → Proxmox → **IP addresses**: the addresses the server gives its VPS, one per line (`203.0.113.10/24
   203.0.113.1`, or a range `203.0.113.20-203.0.113.40/24 203.0.113.1`). Without any, a VPS gets its address by DHCP.
5. **Create on server** clones the template (a full clone, on the template's node), sets the cloud-init user (root by
   default), the service's password and the address, makes the client a Proxmox user `<username>@pve` with the same
   password and the PVEVMUser role on that VPS only, and starts it. If a step fails, the clone is deleted again.
6. Suspend stops the VPS and locks the client's user; terminate deletes the VPS with its disks and the user, and frees
   the address. Change package takes the new template's CPU and memory and grows the disk; change password replaces the
   client's user and sets the cloud-init password (at once through the QEMU guest agent when the VPS has it).
7. The client area's "Log in to Proxmox VE" opens the Proxmox web interface, where the client signs in with realm
   "Proxmox VE authentication server" and finds the console. Start, Shut down and Restart are on their service page.

Every action first asks the panel for the account's current state, so repeating one (or retrying after a timeout)
does nothing harmful. Failures are shown on screen and kept in the Activity log; the service is left
unchanged. Domains, maintenance and other services have no server: the same actions only change their status.

## Registrars and domains

1. Open **Settings → Registrars** and add the easy.gr account with
   its username and password, or the Enom account with its login ID and API token (both stored encrypted). **Email Notifications** is there from the start, with nothing to
   set up: like WHMCS's module of that name, it is for TLDs that have no integrated registrar. It books nothing;
   registering, transferring, renewing, changing nameservers and EPP code requests each email the admins (active,
   Receive system emails on, with access to services) what to do by hand. Its domains are never synced: enter their
   expiry date on the domain. Turn it off under Settings → Plugins.
   Then open **Settings → Domain pricing** and add each TLD you sell with its selling prices for 1, 2 and 3 years to
   register, transfer and renew (empty = not offered), its late renewal fees and its **preferred registrar**.
   New orders (admin and client area) offer only the terms with a price and pre-select the preferred registrar;
   the admin can pick another one on the order or the domain. **Set preferred registrar** changes it for many TLDs
   at once.
2. **Test with a domain** on the registrar page reads one domain's expiry date and nameservers without changing
   anything. From the shell: `php artisan gaiacp:registrar-check example.gr`.
3. **Recording a payment on an active or suspended domain renews it** at its registrar (the one chosen on the
   domain, else its TLD's preferred registrar, longest TLD match) for one billing cycle. The payment stands even if the renewal fails; you
   get a notice and the error is in the Activity log. A pending domain's first payment does not renew (registering
   covers the first term), nor does a monthly-billed one. Reversing a payment does not undo a renewal.
4. **Domains** (sidebar) lists domain services with their registrar expiry. On a domain, the **Registrar** menu
   registers or transfers a pending one (as its *Registration* field says), syncs the expiry date and nameservers,
   changes nameservers and reads the EPP code (at Enom: has it emailed to the domain owner). *Registered elsewhere (track only)* is for a domain at a registrar
   GaiaCP does not manage: it is never renewed or synced. A domain already in your account at a registrar GaiaCP
   manages is *At the registrar* (what *Register* reads as once the domain is active). Set **Default nameservers** under Settings → Domains.
5. `gaiacp:sync-domains` runs daily at 04:00 and reads every domain's expiry date. It never moves the due date;
   compare the two in the Domains list (the expiry turns red when it is before the due date).
6. **Settings → Domains** has the options WHMCS keeps under Domains:
   - *Auto renew new domains*: the default of each domain's **Auto renew** switch (service form → Automation, and
     on each order item). A domain that does not auto renew gets no payment notices or renewal emails and is
     marked Terminated on its due date (it is the service's auto-terminate switch, named for domains).
   - *Renew at the registrar when a payment is recorded* (on): off, a payment only moves the due date.
   - *Free domains renew only with active hosting*: a domain priced 0 gets no payment notice; the daily run renews
     it (a payment of 0) when a paid one would get its notice. With this on, only while the client has active or
     suspended hosting on the same domain or from the same order; otherwise its auto renew is switched off.
   - *Allow IDN domains*: names in Greek (or other) letters, stored as typed, sent to the registrar and cPanel in
     punycode. Off, such names are refused on the forms. Not yet tried against easy.gr or Plesk.
   - *Charge grace and redemption fees*: once a domain's known expiry has passed, its unpaid payment notice gets
     the TLD's **Grace fee**, and the **Redemption fee** on top after the TLD's **Grace days** (Settings →
     Domain pricing). The fees are lines of the notice and are paid with the domain's line.
7. easy.gr calls go over IPv4 with TLS verified (its WHMCS module turned verification off); if the account only
   accepts whitelisted IPs, whitelist the server's IPv4 address. A registrar's **Verify TLS certificate** switch is
   only for while its API certificate is broken (e.g. expired); turn it back on once fixed.
8. Enom answers only the IP addresses allowed on the reseller account (reseller panel → API settings): allow the
   server's address. **Test mode** on the Enom account sends every call to Enom's test environment
   (resellertest.enom.com), which needs its own test account. Enom does not hand out EPP codes: **Get EPP code**
   (admin and client area) asks Enom to email it to the domain owner's address. Not yet tried against a real Enom
   account.

## SSL certificates

1. Switch on **GoGetSSL** under Settings → Plugins and fill in Settings → GoGetSSL: the API username (your GoGetSSL
   login) and the API password you create there (stored encrypted), and how a new certificate is validated by
   default (DNS record, file on the website or email). **Test connection** shows your balance and how many
   certificates you can sell. Orders are paid from your GoGetSSL balance, so keep it topped up. GoGetSSL has no test
   system: to try an order, buy its cheapest certificate and cancel it within its refund days.
2. Make a product of type **SSL certificate**, pick its certificate (for example Sectigo PositiveSSL) and price it
   per cycle. The certificate's shortest period must fit the cycle: a monthly service can't order a yearly
   certificate. Clients order it with the domain it is for.
3. Once the service is set up (on payment, by default) the certificate is ordered and the client is emailed what to
   do; the service page's **SSL** menu lets an admin order it by hand (with the client's own CSR, if they have one),
   check it, change the validation, download, reissue, renew now or cancel it at GoGetSSL.
4. `gaiacp:check-certificates` runs every hour: it emails the client the certificate when it is issued, issues the
   next one inside the order 30 days before a certificate ends, and orders the next period 30 days before the order
   ends when the service is paid beyond it (an unpaid one is left to end).

## Orders

1. **Orders → New order** (or **New order** on a client): pick the client and add items. A product fills in the
   item, and its product options (if any) add to its price and setup fee; a domain's renewal and first-payment (registration or transfer) prices and its 1 or 2 year term come from
   the registrar's TLD table. A hosting item with no domain gets the order's domain.
2. Saving creates every item as a **pending** service. Nothing is called on a server or registrar yet.
3. **Record payment** on the order records the first payment of every item that has none (the domain at its
   registration price), moving each due date forward one cycle as usual.
4. **Complete order** registers or transfers the domain, creates the hosting account on its panel, and activates
   maintenance and other items. An item that fails stays pending with the error in the Activity log; run
   **Complete order** again once it is fixed (finished items are skipped). A pending order can be cancelled.

Each product's **Setup** choice (as in WHMCS) decides when its items are set up: as soon as the order is placed, on
the first recorded payment, on **Complete order** (the default), or never automatically (then **Create on server** /
**Register domain** on the service). The item keeps the choice its product had when it was ordered. The order is
completed once no item is pending.

## Importing from WHMCS

Settings → System → **Import from WHMCS** (or `php artisan gaiacp:import-whmcs`) connects to the WHMCS MySQL database
and only reads it (a read-only MySQL user is enough; to import a `.sql` backup, restore it into an empty database
first). **Dry run** shows what would be imported and every problem found, and saves nothing; **Import** does the same
for real, in one transaction.

| WHMCS | GaiaCP |
|---|---|
| cPanel, Plesk and DirectAdmin servers | Servers (cPanel: the WHM API token; Plesk and DirectAdmin: the password); other types are skipped |
| Products and their EUR prices per cycle, setup fees | Products (one-time products are skipped) |
| Clients; ΑΦΜ from Tax ID or a custom field named ΑΦΜ / VAT, Δ.Ο.Υ. and Επάγγελμα from custom fields | Clients |
| Hosting and other services, with username, password, due date, status, suspension reason, end-of-period cancellation | Services |
| Domains: easy.gr ones at the easy.gr registrar account, others as tracked only; expiry, Do not renew | Domains |
| Billable items | Maintenance services: a recurring item renews on its cycle (due on its unpaid invoice's date, if any); a one-off not yet invoiced is set to auto-terminate; finished, invoiced and "Don't invoice" items are left out |
| Unpaid invoices (optional) | Payment notices, numbered `PF-…`, marked as already emailed; a setup fee joins its service's line; a "Συντήρηση Ιστοσελίδας" (website maintenance) line becomes a one-off Maintenance service set to auto-terminate, so its payment is recorded. An unpaid invoice for a period WHMCS already moved the service past (set up before payment) makes the service due from that period again, with Do not suspend until the date WHMCS had |

Each kind can be left out (the page's Import checkboxes, `--without=domain` etc. on the command): nothing of it is
created, but records of it already in GaiaCP are still used by the rest, and invoice lines for left-out services are
dropped. Run it again later to import the rest.

Passwords are decrypted with `$cc_encryption_hash` from WHMCS's `configuration.php`; the ones that can't be are sent to
WHMCS's own API (`DecryptPassword`, read-only) when a WHMCS URL and API credential are given (`WHMCS_API_*` or the
page's WHMCS API section). Each imported row is remembered
(`whmcs_links`), so running it again only adds what is new; a client with the same email, a server with the same
hostname, a product with the same name or a service with the same domain that is already in GaiaCP is linked rather
than copied, and nothing already here is changed. Not imported: payment history, addons, configurable options,
contacts, TLD prices (set them under Settings → Registrars) and nameservers (the daily domain sync reads them).

## Languages

English and Greek (`lang/el.json`) ship with GaiaCP, plus machine translations
(`Translations::MACHINE_TRANSLATED`) into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and
Turkish: `lang/<code>.json`, `lang/<code>/*.php` (validation, built-in client emails), each plugin's
`lang/<code>.json` and `resources/docs/client-area/<code>.md`; Settings → Languages switches each one on or off, picks the
default one and shows how complete each is. How it works:

- Panel text goes through `__()` with the English text as the key, and Filament's labels are translated too
  (Filament ships its own buttons and tables in Greek and many other languages).
- Each admin picks a panel language on their profile; each client gets an email language on the client form.
- Every email is sent in the client's language: Settings → Email templates gets a Language picker, and a language
  without its own text uses the default language's.

Every email also has built-in text in each shipped language (`lang/<code>/mail_templates.php`); a text you save under
Settings → Email templates for a language replaces it.

Adding another language, e.g. German, from the admin (super admins):

1. Settings → Languages → Download missing texts, language Deutsch: a `.json` of every text with the English as its
   value. Translate the values (a value left in English stays English).
   Or, with the AI assistant on, **Translate with AI** with language Deutsch fills the missing texts itself, a batch
   of 40 per request while the page stays open, and saves them as step 2 does.
2. Upload translations with that file: German appears in the list; switch it on. The texts go to
   `storage/app/private/lang/de.json` (a plugin's to `storage/app/private/lang/plugins/<Plugin>/de.json`), so updates
   of GaiaCP or of a plugin never replace them, and they win over the shipped files.
3. Write that language's version of each email under Settings → Email templates. The client Help page uses
   `resources/docs/client-area/<code>.md`, else English.

When a plugin update brings new texts, they show in English, the update message says how many each language misses,
and Settings → Languages lists them per plugin: download the missing texts again and upload them translated.

For a language shipped with the code (a pull request), `php artisan gaiacp:translations de --write` creates
`lang/de.json` instead (`--plugin=<Folder>` for one plugin's own `lang/de.json`); every `lang/<code>.json` is found on its own.

Activity log entries, System health checks and the WHMCS import report stay in English: they are written once, in
whatever language, and kept as they are.
