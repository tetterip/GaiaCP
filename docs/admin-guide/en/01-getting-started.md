# Getting started

This guide is for the people who run a hosting business with GaiaCP: the admins who sell, bill, set up and support.
It explains what each part of the admin panel is for and the order to set things up in. Developers have their own
guides: [writing a plugin](../../plugin-tutorial.md) and [using the API](../../using-the-api.md).

GaiaCP has two sides. The **admin panel** at `/admin` is where you work. The **client area** at the site root is where
your clients log in to order, pay and look after their services. Everything a client does there you can see and undo
here.

## Install

GaiaCP needs PHP 8.3 or newer and a MySQL or MariaDB database.

1. Create an empty database and a user for it.
2. Upload and unzip the release zip (GitHub → Releases → `gaiacp-<version>.zip`) at the site's root or in a subfolder
   such as `example.com/billing/`. Point the document root at its `public/` folder if your hosting lets you; on Apache
   and LiteSpeed it also works as it is, thanks to the `.htaccess` it ships with. On nginx, see [Document root](../../installation.md#document-root).
3. Open the site. Every page goes to the installer, which checks PHP, asks for the database, your business name, the
   region and the first admin. Run it right after uploading: until it finishes, anyone who opens the site can.
4. Add the cron line the installer shows you. Without it nothing automatic happens: no payment notices, no reminders,
   no suspensions. It runs every minute:

   ```
   * * * * * cd /path/to/gaiacp && php artisan schedule:run >> /dev/null 2>&1
   ```

From SSH, `php artisan gaiacp:install` asks the same questions in the terminal.

## The setup guide

Log in at `/admin` with the admin you created. The dashboard opens with **Finish setting up GaiaCP**, the setup
guide. It takes you through the rest in order: your business, region and languages, mail, payment methods, tax, a
server, domain prices, your first product, the client area and terms, the public area
(what visitors see before they log in), backups, cron and two-factor sign-in. Each step
opens the page where you do it and ticks itself once it is done. Skip the steps you don't need; **Hide** takes the card
off the dashboard. It stays under Settings → General → Setup guide.

Before that, on the first login, a short **tour** lights up the menus one by one: clients and orders, services, payment notices,
support, the search and the bell, and this guide. Each admin gets it once, with only the parts their access level
opens; the menu under your name → **Tour** shows it again. Admins who were there before the tour came don't get it by
themselves.

The chapters of this guide follow roughly the same order, so you can read them alongside it.

## Finding your way around

The **sidebar** has five groups:

- **Main**: the Dashboard, Clients, Orders, Messages to clients and Leads.
- **Billing**: Payment notices, Quotes, Payments and Reports.
- **Services**: Hosting, Domains and Other services, Site checks, and the Upgrade and Cancellation requests clients send.
- **Support**: Tickets, the Knowledge base and Announcements.
- **System**: the Activity log, Settings and this Help.

**Settings** (in the sidebar and in your user menu) is a page of cards in groups: General, Billing, Products & domains,
Client area, Support, Email & notifications, Integrations, AI assistant, Security, System and Logs. Its search box finds a setting by
name. Each settings page has its own Save; the bar at the bottom tells you when something is not saved yet.

The **top bar** has the search (press `/` or Ctrl/⌘ + K: clients, services and domains, leads, quotes and tickets), a link
that opens the client area in a new tab, **New order**, and the **bell**. On a phone or
tablet, swipe a notification left or right to remove it.

Press `?` anywhere to see the keyboard shortcuts: `g` then a letter goes to a page (`g c` Clients, `g d` Dashboard),
`n` then a letter starts a new record.

## Your profile

Your user menu → Profile is yours alone:

- **Language** of the panel, and **Theme** (Gaia or Flat; the default is set in Settings → General).
- **Two-factor authentication**: after your password you also type a code from an app on your phone. Turn it on: a
  stolen password alone then can't open the panel.
- **Notifications**: whether the bell shows you what happened (new orders, payments, tickets, servers going down…).
  It never tells you about what you did yourself, and only about the areas you can open.
- **Push notifications**: the same notifications on your phone or computer even while GaiaCP is closed. Add GaiaCP to
  your phone's home screen first (it works as an app), then turn push on for that device.

## The dashboard

The dashboard shows what needs you today: the open balance (what clients owe, what is past due and due in 7 days),
orders and income over time, the renewals coming up, your servers with their accounts and uptime, the support tickets
waiting for an answer and **Needs attention** (cron stopped, pending orders, client change requests, overdue notices,
failed panel or registrar calls, leads to contact today).

The cards sit in two columns of the same width, each filling down on its own so a short card leaves no gap under it;
on a phone the first column comes first, then the second. To move a card, drag it by the handle at the middle of its
top edge (it shows when you point at the card) to another place in its column or into the other one, or focus the
handle and press the arrow keys (left and right change column); the new layout is saved at once. **Show / hide cards** picks which cards show, and **Back to the default** there puts the order and
the hidden cards back. The layout is yours; other admins keep theirs. Admins with fewer rights see only the cards for
the areas they can open.

## What GaiaCP does not do

Two things on purpose, so you know where they happen instead:

- **It does not send invoices to a tax authority.** Its "payment notices" are proformas: they say what is owed and by
  when. Invoices are your choice: issue them in your accounting software, or turn on Settings → Billing → **Invoices**
  so that each paid notice also gets a numbered invoice, with credit notes to undo one (see
  [Billing](06-billing.md)). Sending them to your tax authority (myDATA, Peppol…) is not built in.
- **It never charges a card by itself.** You record payments, or a payment plugin (Stripe Checkout, PayPal) records what
  the client paid on the provider's own page. There are no saved cards and no automatic charges.

Next: [Your business and email](02-business-and-email.md).
