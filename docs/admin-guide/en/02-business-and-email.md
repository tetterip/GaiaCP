# Your business and email

Before you add clients, tell GaiaCP who you are and how to send email. All of it is under **Settings → General** and
**Settings → Email & notifications**.

## Company

Settings → Company holds your business name, email, phone, website, address, VAT number and tax office. Client emails
are sent from this name and address and signed with these details, and payment notices print them.

The **logo** (SVG, PNG or JPG, wide rather than tall) shows on the client area's login page and menu, on payment
notices and at the top of every client email. You can add a light version for dark mode and a square browser tab
icon. The admin panel keeps the GaiaCP logo: yours is for your clients.

## Region and languages

Settings → General → **Region** sets:

- the **timezone**: due dates, the daily run and every time shown follow it;
- the **date format** used in tables and date pickers;
- the **country** new clients start in (phone numbers without a country code are read as that country's).

The same page has **Create payment notices … days before the due date** (14 by default, see
[Billing](06-billing.md)), the admin panel's default theme and the captcha (see
[Admins and security](12-admins-and-security.md)).

Settings → **Languages** picks the languages admins and clients can use and the default one. English and Greek ship
with GaiaCP, and so do German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish, marked
*machine translated* until someone who speaks them has checked them (corrections are welcome: upload them here, or
send them to the project). An install that existed before they shipped keeps offering only the languages it had: tick
the new ones here to offer them. Each admin picks a panel language in their profile; each client has an email language on the client form,
and every email to them goes out in it. To add another language, download its missing texts here, translate the
values in the file and upload it. Uploaded translations survive updates. With the AI assistant on, **Translate with
AI** (super admins) fills a language's missing texts for you, a batch every few seconds while the page stays open,
and saves them as uploaded translations; **Stop** ends it, and pressing it again later carries on.

## Mail

Settings → **Mail** sets how emails leave GaiaCP. Turn on **Send through an SMTP server** and fill in your mail
server (host, port, encryption, username, password), or leave it off to use the mailer in `.env`. **Send test email**
tries the values before you save them.

The **header** and **footer** text wrap every client email (Markdown or HTML, with placeholders such as
`{$client_first_name}` and `{$company_name}`): a good place for your contact details.

**Email log** (Settings → Logs) lists every email sent, with its text, and the ones that failed with the reason. Look
there first when a client says they got nothing.

## Email templates

Settings → **Email templates** lists every email GaiaCP sends to clients, grouped as General, Billing, Hosting,
Domains, Support and so on. Each one says when it is sent. Open one to:

- switch it **on or off** (only a few are on in a new install: switch on the ones you want, such as Hosting Account
  Welcome Email and Order Confirmation);
- change its **subject** and **text**, with the placeholders listed beside it;
- write it **per language**: pick the language at the top. A language without its own text uses the built-in one;
- **preview** it with sample values.

Emails that carry a password or a sign-in link are never shown in full in the logs or in the client's email history.

Admins get their own system emails (new orders, tickets, the daily summary, backups). Each admin can turn them off with
**Receive system emails** on their user (Settings → Admin users), and Settings → Automation turns the daily summary off
for everyone.

## Payment methods

Settings → **Payment methods** lists how clients can pay. Each method is a plugin with its own page:

- **Bank transfer**: your bank details, printed on every payment notice.
- **Stripe**: card payments on Stripe's own Checkout page, from the client's unpaid payment notice. Fill in the test
  or live secret key and the webhook signing secret, and add the webhook address shown there in your Stripe dashboard.
  The payment is recorded once Stripe confirms it, never from the client's visit alone.

Payments you record by hand can also be **Cash** or **Other**. Each client has a default payment method on their form.

## Tax

Settings → **Tax**: whether payment notices charge tax at all, whether your prices are entered **without tax** (added on
top) or **with tax** (already inside), and the rules: a rate for all countries and rates for single countries (the
client's country wins). Clients marked **Tax exempt** are never charged. A notice keeps the rate it was created with.

The **VIES VAT check** plugin (off by default) checks EU VAT numbers from the client form and can apply the **reverse
charge**: no tax for businesses in another EU country whose VAT number VIES found valid, with a note on the notice.

Next: [Products](03-products.md).
