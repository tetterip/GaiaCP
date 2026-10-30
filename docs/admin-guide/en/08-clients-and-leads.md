# Clients and leads

## Clients

Main → **Clients** lists your clients. A client holds the details a domain registration and a payment notice need:
first and last name, company, email, phone, a structured address and country, VAT number and tax office, plus the
email language, the currency, the default payment method and two email switches: **Renewal reminders** and **Overdue
notices**. **Tax exempt** keeps tax off their notices.

Keep the address complete: it is the registrant contact sent to the registrar for their domains. The buttons
by the VAT number check it and fill in the company's details where a plugin offers that (VIES for EU VAT
numbers, ΑΑΔΕ for Greek ones).

**Client fields** (Settings → General → Client fields) adds fields of your own: text, several lines, a list of choices,
yes / no or a date, each required or not, hidden from the client, shown to them or editable by them, and optionally
printed on payment notices.

### The client page

The top of a client's page sums them up: what they owe, their services, and a **Payment record** that rates how they
pay (on time, watch, late) from the last 12 months. Below it are tabs for Details, Hosting, Domains, Other services,
Orders, Payment notices, Payments, Tickets and Activity, plus Invoices while invoices are on (or once they have one)
and Sign-ins once they have a login.

The buttons at the top:

- **New order** for this client.
- **Client area** → **Invite to client area** emails them a link to set their password; **Log in as client** opens the
  client area as they see it, with a bar to go back (it works before they are invited, and leaves no trace in their
  sign-ins). The same menu turns their login off and on.
- **Credit** → add or take off credit, when client credit is on.
- **Suggestions** (with the AI assistant on, for admins who also see billing): what the client might buy, signs they
  may leave and what to look after, from their services, prices, payment history and ticket subjects. Nothing is saved.

When a client changes their name, company, tax details or address in the client area, the change waits for you: the
client page shows **Review requested changes** to approve or decline it, and the Clients menu shows a count. Email and
phone changes are saved at once.

A new client can get a client area password straight away (tick **Email the login details**), or the Client Signup
Email.

### Records with history

GaiaCP doesn't delete what has history. A client with services, a server with services and a service with payments have
no Delete: terminate or cancel the services instead.

A service's client is fixed once it is set up. To give a service to someone else (a website sold, a business renamed),
use **Move to another client** on the service: its unpaid lines leave the old client's notices, and past payments stay
with the client who made them.

## Leads

Main → **Leads** keeps prospective clients apart from your clients until they buy: who they are, where they came from
(Settings → Sales → Lead sources), what they are looking for, dated notes, which admin has them and the **next contact**
day. On that morning the bell reminds the admin it is assigned to (or everyone with the Sales area), and the dashboard
lists the leads to contact today.

- **Add note**: a note on a New lead marks it Contacted.
- **New quote** writes them an offer; sending it marks them Quoted.
- **Convert to client** makes them a client (or links them to the client who already has their email), optionally with
  a client area invitation. Accepting a quote does the same.
- **Mark as lost** keeps the reason; **Reopen** brings them back.

Your website's contact form can add leads through the [REST API](../../using-the-api.md).

Next: [Automation](09-automation.md).
