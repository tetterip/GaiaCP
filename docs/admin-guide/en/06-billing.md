# Billing

GaiaCP bills per service. Each service has a **price**, a **billing cycle** and a **next due date**. Payment notices
tell the client what is owed; recording a payment moves the due date forward one cycle. That is the whole loop.

## Payment notices

A **payment notice** (a proforma, numbered `PF-2026-0001`) lists what a client owes on one due date. It is not a legal
invoice: issue those in your accounting software. You find them under Billing → **Payment notices**, and on each
client's Payment notices tab, newest first, with buttons to show only the unpaid, paid, cancelled or overdue ones.

The daily run makes them for you:

1. **Create**: Settings → General → Create payment notices sets how many days before the due date (14 by default). The
   client gets one notice per due date with every service due that day, emailed as it is created.
2. **Remind**: on the days before the due date set in Settings → Automation (14, 7 and 1 by default) that come after
   it was emailed, an unpaid notice is sent again as a reminder.
3. **Overdue**: on the days after the due date (1, 7 and 14 by default), the first sends the First Payment Notice
   Overdue email and the later ones the Second.

Each email goes once: running the daily job again, or missing a day, never sends one twice; a missed day sends only the
most urgent one. Clients can switch off renewal reminders and overdue notices on their client form.

A new order gets its own notice at once, for the first payment of its items.

On a notice you can:

- **Record payment**: records every unpaid line at once.
- **Pay with credit**, when the client has credit that covers lines.
- **Send email**: sends it again (the Payment Notice Modified email).
- **Personal reminder** (with the AI assistant on): the reminder email, or the first overdue one once it is late,
  rewritten for how this client pays: lighter for one who is rarely late, firmer for one who often is. Read it, change
  it if you like and **Send** it.
- **Update client details**: a notice keeps the client's name, address and VAT details from the day it was issued;
  this takes the current ones, while it is unpaid.
- **Cancel** it.

Ending a service (Terminate or Cancel) takes it off its open notices; a notice left empty is cancelled.

## Recording payments

When a client pays by bank transfer or cash, record it:

- on the payment notice (**Record payment**), which pays all its lines;
- or on the service (**Record payment**), shown while something is owed. To take a payment ahead of time, use
  **Price → Record early payment**.

Recording a payment moves the service's due date forward one cycle, renews a domain at its registrar, and unsuspends a
hosting account that was suspended for not paying. If the panel fails to unsuspend, the payment is still kept; use
Account → Unsuspend once the server answers.

Entered one by mistake? The service's **Payments** tab has **Reverse** on the latest payment: it deletes it and sets
the due date back. Reversing never undoes a domain renewal.

Billing → **Payments** lists every payment, by hand or by card. Card payments with Stripe are recorded by themselves
once Stripe confirms them. Refunds are made in Stripe, not in GaiaCP.

## Billing cycles and due dates

Cycles run from monthly to triennially, plus **One time** for other services billed once. Renewals keep to the day of
the month the service started on: a service started on the 31st comes back to the 31st after a short month. A one-time
service's due date never moves; once it is set up and paid it is **Completed** and gets no more notices.

You can change a service's due date by hand on its form, for example to line it up with a domain's real expiry.
**Do not suspend until** (Automation section) keeps one service from being suspended until a date, when you have
agreed to wait.

## Client credit

Settings → Billing → **Client credit** (off by default) lets clients keep a balance. Clients with an active service
add funds in the client area through an "Add funds" notice, paid like any other; the balance then pays their notices, a
whole line at a time, by itself in the daily run or with **Pay with credit**. You add or take off credit on the client
page's **Credit** menu, with a reason the client sees in their credit history. The page sets the smallest and largest
deposit and the most a client may hold.

## Currencies

The base currency (EUR unless you chose another at install) is the one totals and reports use. Settings →
**Currencies** adds others with their format and rate: by hand or from the European Central Bank, daily if Settings →
Automation says so. Each client is billed in one currency, fixed once they have billing. Products and TLDs can have
their own prices in a currency or follow the rate. A service and its notices stay in the currency it was created in.

## Quotes

Billing → **Quotes** are offers to a lead or a client. See [Orders and quotes](07-orders-and-quotes.md).

## Reports

Billing → **Reports**:

- **Annual income**, month by month against the year before; **Monthly transactions**, day by day.
- **Income by product** for a period.
- **Income forecast**: what active services bring in each month if every renewal is paid, after your servers' costs
  and the domains' registrar costs.
- **Server revenue**: accounts per server, what they bring in and the renewals ahead.
- **Domain profit** per TLD.
- **Domains** and **Services**: pick the fields, filter, and export to CSV.
- **Sales opportunities**: domains to transfer to you, domains without hosting and expired domains still within their
  grace days, each with an email to send.
- **Lead sources**: where your leads came from and how many became clients.

Every report exports to CSV.

Next: [Orders and quotes](07-orders-and-quotes.md).
