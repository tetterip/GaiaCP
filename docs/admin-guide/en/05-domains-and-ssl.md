# Domains and SSL

A domain in GaiaCP is a service of type Domain: it is billed, reminded and paid like any other service, and it has a
registrar side (expiry date, nameservers, EPP code) that GaiaCP reads and changes through the registrar's API.

## Registrars

Settings → Products & domains → **Registrars** holds your registrar accounts (super admins only). **easy.gr** and
**Enom** are bundled plugins; others can be added as plugins. Add the account with its login (stored encrypted) and
press **Test with a domain**: it reads one domain's expiry and nameservers without changing anything. From SSH,
`php artisan gaiacp:registrar-check example.com` does the same.

Most registrars answer only the IP addresses you allow on your reseller account: allow your GaiaCP server's.

**Email Notifications** is there from the start for TLDs with no integrated registrar. It books nothing: each
register, transfer, renewal, nameserver change or EPP code request emails the admins what to do by hand. Its domains
are never synced, so enter their expiry date on the domain yourself.

## Domain pricing

Settings → **Domain pricing** lists each TLD you sell with its prices for 1, 2 and 3 years to **register**,
**transfer** and **renew** (leave a term empty to not offer it), and its **preferred registrar**. New orders, in the
admin and the client area, offer only the priced terms and pick the preferred registrar; you can choose another on the
order or on the domain. **Set preferred registrar** changes it for many TLDs at once.

Each TLD can also keep:

- the **registrar cost** per term: the list then shows your renewal margin (red when a term sells at a loss), and
  Reports → Domain profit shows revenue, cost and profit per TLD;
- **grace days**, a **grace fee** and a **redemption fee** for late renewals (see below);
- prices in other currencies, or follow the exchange rate;
- a **sale**: lower register and transfer prices between two days (Sale from / Sale until) for domains ordered then,
  for their first term; renewals stay at the renew price. Clients see the normal price struck through on the order
  page, the domain search and Domain prices; the day after the sale the normal prices are back by themselves.

## Domain settings

Settings → Products & domains → **Domains**:

- **Default nameservers** for new registrations.
- **Auto renew new domains**: the default of each domain's Auto renew switch. A domain that doesn't auto renew gets no
  payment notices and is marked Terminated on its due date.
- **Renew at the registrar when a payment is recorded** (on by default). Off, a payment only moves the due date.
- **Free domains renew only with active hosting**: a domain priced 0 is renewed by the daily run while the client
  still has hosting on it.
- **Allow IDN domains**: names in Greek or other letters.
- **Charge grace and redemption fees**: once the expiry has passed, the domain's unpaid notice gets the TLD's grace
  fee, and the redemption fee on top after its grace days.
- **SSL with domains**: offer an SSL certificate product with domains ordered on their own, with a discount on its
  first period.

## Working with a domain

**Services → Domains** lists your domains with their registrar expiry next to their due date; the expiry turns red
when it falls before the due date. On a domain's page:

- **Registration** says what happens when it is set up: **Register**, **Transfer in** (with the EPP code), or
  **Registered elsewhere (track only)** for a domain at a registrar GaiaCP doesn't manage, which is billed but never
  renewed or synced. Once active, Register reads as "At the registrar".
- The **Registrar** menu registers or transfers a pending domain, **syncs** the expiry and nameservers, **changes
  nameservers** and gets the **EPP code** (Enom emails it to the domain owner instead). Where the registrar offers
  them it also edits the **contact details**, **private nameservers** (ns1.the-domain) and the **registrar lock**.
- **Auto renew** is in the Automation section.

The domain's registrant is the client: their name, company and structured address on the client form go to the
registrar, so keep them complete.

### Renewals

**Recording a payment on an active or suspended domain renews it** at its registrar for one billing cycle. The payment
stands even if the renewal fails: you get a notice and the error is in the Activity log, so renew it by hand once the
problem is fixed. A domain's first payment after registering doesn't renew it again (registering covers the first
term), and reversing a payment doesn't undo a renewal.

Every night at 04:00 GaiaCP reads every domain's expiry date and nameservers. The sync never moves the due date: if a
domain was renewed elsewhere, change the due date yourself. The admins' daily summary lists syncs that changed
something or failed.

Clients can change nameservers, get the EPP code and turn auto renew on or off from their domain page, each behind its
own switch under Settings → Client area. They also get a **Domain search** that checks names with the registrar (or
public RDAP / DNS records) before they order.

## SSL certificates

SSL certificates are sold through the **GoGetSSL** plugin (off by default):

1. Switch it on under Settings → Plugins and fill in Settings → **GoGetSSL**: your API username and password and how
   new certificates are validated by default (DNS record, a file on the website, or email). **Test connection** shows
   your balance. Orders are paid from your GoGetSSL balance, so keep it topped up. There is no test system: to try an
   order, buy the cheapest certificate and cancel it within its refund days.
2. Make a product of type **SSL certificate**, pick its certificate and price it per cycle. The certificate's shortest
   period must fit the cycle.
3. Once the service is set up (on payment, by default), GaiaCP makes the key and CSR, orders the certificate and
   emails the client how to validate the domain. The service's **SSL** menu lets you order it by hand (with the
   client's own CSR if they have one), check it, change the validation, download, reissue, renew or cancel it.
4. Every hour GaiaCP looks for issued certificates and emails them to the client. Certificates now last at most 200
   days, so inside a longer order the next one is issued 30 days before the current one ends, and the next period is
   ordered 30 days before the order ends when the service is paid beyond it.
5. When the certificate's domain is the client's own hosting account on one of your cPanel or Plesk servers, GaiaCP
   does the validation itself: on cPanel it puts the file in the website's `.well-known/pki-validation` folder, on
   Plesk it adds the DNS record to the subscription's zone (that only helps when the domain uses the server's
   nameservers). The client gets no validation email then. Once the certificate is issued, GaiaCP installs it on that
   account and the website uses it; **SSL → Install on hosting** does it again by hand. This needs the private key
   that GaiaCP made (not the client's own CSR) and can be switched off under Settings → Domains → **Set up
   certificates on our own hosting**.

Next: [Billing](06-billing.md).
