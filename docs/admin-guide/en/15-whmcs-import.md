# Moving from WHMCS

Settings → System → **Import from WHMCS** copies your WHMCS data into GaiaCP. It connects to the WHMCS MySQL database and
only reads it: a read-only MySQL user is enough. To import from a `.sql` backup, restore it into an empty database
first.

## Before you start

1. Set up GaiaCP's basics first: your company, region, currencies and tax (WHMCS prices are imported in your base
   currency).
2. Add the registrar accounts you use (Settings → Registrars), so imported domains land on them.
3. Have WHMCS's `$cc_encryption_hash` ready, from its `configuration.php`: it decrypts the hosting passwords. Without
   it, or for passwords it can't decrypt, give a WHMCS URL and API credential and GaiaCP asks WHMCS's own API (read
   only).

## Dry run, then import

**Dry run** shows what would be imported and every problem found, and saves nothing. Read the problems, fix what you
can in WHMCS or GaiaCP, and run it again. **Import** then does the same for real, in one go: if anything fails,
nothing is saved.

Each kind (servers, products, clients, client logins, services, domains, unpaid invoices) can be left out with its tick box. Run the
import again later for the rest: everything imported is remembered, so a second run only adds what is new. A client
with the same email, a server with the same hostname, a product with the same name or a service with the same domain
already in GaiaCP is linked rather than copied, and nothing already here is changed.

From SSH: `php artisan gaiacp:import-whmcs --dry-run`, with the database details as options and `--without=domain` to
leave a kind out.

## What comes across

| WHMCS | GaiaCP |
|---|---|
| cPanel, Plesk and DirectAdmin servers | Servers (other types are skipped) |
| Products, their prices per cycle and setup fees, product groups | Products and product groups (one-time products are skipped; stock control becomes Quantity available) |
| Clients, with tax ID and custom fields | Clients; other custom fields become client fields |
| Client logins (WHMCS 8 users, or the client's password in older versions) | Client area logins, turned on (off for Closed clients), no email sent: clients log in with the email and password they had in WHMCS |
| Hosting and other services, with username, password, due date, status and end-of-period cancellations | Services |
| Domains: each on your registrar account for its WHMCS registrar (added under Settings → Registrars); domains at a registrar with no account here as tracked only | Domains |
| Billable items | Maintenance services |
| Unpaid invoices (optional) | Payment notices, marked as already emailed |

Not imported: payment history, addons, configurable options, contacts, TLD prices (set them under Domain pricing) and
nameservers (the nightly domain sync reads them).

## Client logins

Clients keep their WHMCS password: GaiaCP copies only WHMCS's hash of it (WHMCS never had it in plain text either). The
first time a client logs in with it, GaiaCP stores it with its own hash and forgets WHMCS's; a password set or reset in
GaiaCP ends the WHMCS one at once. A client who already has a login here is left as it is, and WHMCS's two-factor
authentication is not carried over (clients can turn on GaiaCP's from their profile). A password GaiaCP can't check,
or an email another user already logs in with, is listed under the problems; those clients use **Forgot password?**.

Imported your clients before this existed? Run the import again with only **Client logins** ticked: it adds the logins
of the clients already here and changes nothing else.

## After the import

- Check a few clients, services and due dates against WHMCS.
- Run **Compare with the server** on each server (see [Servers](04-servers.md)) to catch accounts that don't match.
- Set your TLD prices and preferred registrars under Domain pricing.
- Switch on the email templates you want, then turn off WHMCS's cron so clients don't get two sets of reminders.
- Preview the first daily run with `php artisan gaiacp:send-reminders --dry-run` before it happens for real.

This is the last chapter. Back to [Getting started](01-getting-started.md).
