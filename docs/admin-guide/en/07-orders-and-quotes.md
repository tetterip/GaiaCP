# Orders and quotes

An **order** groups the services a client buys together, such as a domain with its hosting and a maintenance plan. The
items of an order are the services themselves: they start **Pending** and become **Active** once set up.

## A new order

Press **New order** (top bar, or on a client's page) and pick the client. Add the items:

- a **product** fills in the item's price, cycle, server and package; its options add to the price and setup fee;
- a **domain** takes its prices and terms from Domain pricing: choose **Register**, **Transfer in** (with the EPP code)
  or **Registered elsewhere (track only)**, the years, the registrar (its TLD's preferred one by default), auto renew
  and nameservers. The **First payment** is the registration or transfer price when it differs from the renewal one;
- a hosting item with no domain of its own gets the order's domain.

Saving creates every item as a pending service and a payment notice for the first payment, emailed at once (with the
Order Confirmation email, if it is on). Nothing is called on a server or registrar yet.

## Payment and setup

**Record payment** on the order records the first payment of every item that has none, moving each due date forward
one cycle as usual.

**Complete order** sets everything up: it registers or transfers the domains, creates the hosting accounts, then
activates the rest. Its window lets you pick each domain's registrar and each hosting account's server and package
first. An item that fails stays pending with the error on screen and in the Activity log; fix the cause and press
**Complete order** again (finished items are skipped).

Each product's **Setup** choice decides when its items are set up without you: as soon as the order is placed, on the
first recorded payment, on Complete order (the default), or never (you then use Account → Create on server or the
Registrar menu on the service). The order is completed once no item is pending.

A pending order can be cancelled with **Cancel order**, which cancels its items and payment notice. With Settings →
Automation → **Cancel unpaid orders after** set, the daily run does it for orders that are still unpaid after that
many days; an order with a payment or an item already set up is never cancelled.

## Orders from clients

With Settings → Client area → orders on, clients order from the client area: they add products (with their domain,
options and add-ons) and domains on their own to a cart, accept your terms of service when you ask for them, and place
it. That makes one order with one payment notice, exactly as New order would, and emails the admins who can open
clients (Settings → Automation → New order email). The **public order page** (`/cart`, off by default) lets visitors
fill the cart before they sign up or log in.

## Coupons

A **coupon** (Billing → **Coupons**) is a code that lowers the price of what it covers: a **percentage**, or a **fixed
amount** off each item (in your base currency, converted for clients who pay in another). Choose the products and the
domain endings it covers, or leave both empty for everything; a setup fee is never lowered, and for a domain it lowers
the registration or transfer price. With **Renewals too** off, only the first payment is lower; with it on, the service
keeps the lower price at every renewal. **Valid from** / **Valid until**, a **Usage limit** (a cancelled order gives
its use back), **Once per client** and **New clients only** limit who can use it; switch it off to stop it.

Clients type the code in the cart, where they see what it saves before they place the order. You can also send a link
such as `https://your-site/checkout?coupon=SPRING` (or add `?coupon=SPRING` to the order page): the code is filled in
for them. On **New order** pick it in **Coupon**; the REST API takes a `coupon` with an order. The order shows the
coupon and what it took off, and the notice line names it. When a coupon leaves nothing to pay, the order's notice is
recorded as paid at once. The list shows how many times each coupon was used; a used coupon can't be deleted.

Orders are listed under Main → **Orders**, with buttons for the pending, completed and cancelled ones. The dashboard's
Needs attention lists pending orders.

## Quotes

A **quote** is an offer, under Billing → **Quotes**, for a lead or a client. Write it like a new order: products,
options and domains, with prices you can lower for a discount (kept on renewals), plus **one-off lines** such as moving
a website, which are billed once. Set how long it is valid (Settings → Sales sets the default days and a footer).

- **Send** emails a link to the quote's page, where the client prints it, **accepts** it (with the terms of service
  when you ask for them) or declines it with a reason, without logging in.
- Accepting places the order with its payment notice; a lead becomes a client with a client area invitation. The bell
  tells your Sales admins.
- Sent quotes past their date are marked Expired every night. **Duplicate** starts a new quote from an old one.
- **Accept for the client** and **Mark as declined** are there for answers given by phone or email.

Clients also find their quotes in the client area under Billing → Quotes.

Next: [Clients and leads](08-clients-and-leads.md).
