# Products

A **product** is something you sell: a hosting package, a domain, a yearly maintenance plan. Products live under
Settings → Products & domains → **Products**. They only fill in a new service: once a service exists it keeps its own
copy of the price, cycle, server and package, so changing a product never changes what existing clients pay.

## Types

- **Hosting**: an account on one of your servers (cPanel / WHM, Plesk, HestiaCP, DirectAdmin). Set up the servers
  first: [Servers](04-servers.md).
- **Domain**: a domain name at a registrar. Domains are priced per TLD under Domain pricing, not as products; see
  [Domains and SSL](05-domains-and-ssl.md).
- **SSL certificate**: a certificate bought through the GoGetSSL plugin.
- **Maintenance**: work you do for the client on a cycle, such as website care. Clients see these read-only and can't
  order them; only you set them up.
- **Other service**: anything else. Other services can also be billed **One time** (moving a website, a design job):
  billed once and shown as Completed once paid.

## A new product

- **Name**: the label clients see on their services and in reminders.
- **Default cycle**: the billing cycle picked first on a new service or order.
- **Servers** and **Package** (hosting): the servers that have this package, and its name on the panel (the
  service plan on Plesk). A new order goes to the least full active server; see [Servers](04-servers.md).
- **Pricing**: switch on each cycle you offer (monthly to triennially) with its price and an optional **setup fee**,
  charged once with the first payment.
- **Setup**: when a new service of this product is set up: as soon as the order is placed, when the first payment is
  recorded, when you complete the order (the default), or never by itself (you use the Account or Registrar menu).
- **Hidden**: not offered in New order, but still available on a single new service.
- **Retired**: offered nowhere any more; services that have it keep it.
- **Show in client area**: off, clients can never order it and its services are hidden from them.

A product with services has no Delete: retire it instead.

## Product groups

Settings → **Product groups** sorts your products on the order pages: Shared hosting, VPS, Email and so on. Each group
has a name per language, an icon and its own link (`/order/<group>`), and can show a **compare table**: list the
features (Disk space, Websites, Free SSL…) and fill in each product's values on the product form. A hidden group is
reached only through its link, handy for an offer you send to a few people.

Tick **Popular** on a product (its Order page section) to give it a badge on its order card, in the compare table and
on the public home page.

## Product tags

Settings → **Product tags** holds your own labels: New, SSD, Legacy plan. Each has a name per language, a colour and a
**Shown to clients** switch, and you pick them on the product form (its Order page section). A tag shown to clients is
a badge after Popular in the same places (the first three of a product); every tag, shown or not, filters the Products
and Services lists and Messages to clients (With products tagged), so a hidden one is handy for your own grouping.

## Product options

Settings → **Product options** holds choices offered with a product, like WHMCS's configurable options: a server
location, extra IP addresses, more disk. Make a group of options, attach it to products, and give each option a type
(a list, buttons, yes / no, or a quantity) and each choice a price per cycle (or a monthly price that is multiplied by
the months) and a setup fee.

New order and the service form offer them, the service's price includes them, and the payment notice names them.
Clients pick the ones not marked hidden on the Order page. Options are billing only: nothing changes on the server, so
set up what the client chose by hand.

## Add-ons

Tick **Add-on** on a product to sell it only on top of another service (for some products or any): daily backups, a
dedicated IP, priority support. An add-on is added from a service's page (**Add-ons** tab) and becomes a service of
its own on the parent's cycle and due date, so both go on one payment notice. On a running service it is first owed
on the parent's next due date. It is suspended, unsuspended, ended and moved with its parent.

Clients can add add-ons to an active service from its page (they pay for the days until the next due date first), or
tick them under the product when they order. Like options, add-ons are billing only.

## Upgrades and downgrades

On a service, **Price → Upgrade / downgrade** moves it to another product of the same type. The new package is set
on the server at once; the new price applies from the next due date, unless you tick **charge the difference now**,
which bills the days left at the new price (or puts a downgrade's difference into client credit, when that is on).
Clients can ask for it from their service; you approve or decline under Services → **Upgrade requests**.

## Changing prices

To raise (or lower) prices for existing clients, use **Change price**: on one service (Price menu), on several in the
Services list, on a product or on a TLD. Choose the current price plus a percentage or an amount, or the product's
current price, and the date it starts. The date must be at least Settings → Automation → Price change notice ahead (30
days by default), so clients hear in time. Each client gets one **Price Change Notice** email listing their services,
and the client area shows the coming price. Payment notices already issued keep the old price.

Next: [Servers](04-servers.md).
