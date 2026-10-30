# The client area

The client area is your clients' side of GaiaCP, at the site root. Clients log in to see their services, domains,
payment notices and payments, pay by card, order, open tickets and read the knowledge base. Its own **Help** page
explains it to them, showing only the parts you have switched on.

To see it as a client does, open a client and press **Client area → Log in as client**.

## Giving clients a login

Clients don't have a login until you give them one or they sign up:

- **Invite to client area** (client page → Client area menu) emails a link to set their password.
- A new client can get a password at once with **Email the login details**.
- **Sign up** (Settings → Client area, off by default) lets anyone create an account; their email address is confirmed
  first.
- **Google sign-in** (a plugin) adds "Continue with Google" with your own Google OAuth client.

Clients manage their own password, two-factor authentication and signed-in devices from their profile. Your captcha and
login security settings protect their login too; see [Admins and security](12-admins-and-security.md).

## What clients can do themselves

Settings → Client area switches each self-service action on or off:

- **Domains** (active domains at a registrar you manage): change nameservers, get the EPP code, turn auto renew on or
  off, and, where the registrar offers them, contact details, private nameservers and the registrar lock.
- **Hosting** (active accounts): log in to cPanel or Plesk with one click and open its pages through quick shortcuts,
  change the password, see disk and bandwidth usage and the server's uptime.
- **Orders**: order products and domains through a cart, add add-ons, ask for an upgrade or a cancellation.
- **Links in emails**: a "View in the client area" button at the end of client emails.
- The **Notifications bell** and **Email history**.
- The **Welcome tour**: on a client's first login, a few cards on their services, payment notices, ordering, support
  and profile; their menu → Tour shows it again. It doesn't open by itself when you look as the client.

Every change a client makes goes through the same actions you use, is in the Activity log, and the requests (profile
changes, cancellations, upgrades) wait for your answer.

## How it looks

- **Theme** (Gaia or Flat) and a **Brand colour** for buttons and links. The admin panel keeps its own look.
- Your **logo** from Settings → Company, with a dark mode version and a tab icon.
- The **languages** offered (Settings → Languages): clients pick theirs.

## Terms of Service

Turn on **Clients must accept the terms of service** and give a link to your terms, their text, or both. Clients tick
them on every order, on sign up and when accepting a quote, and GaiaCP keeps the date and version they accepted on the
order or the client. The version changes by itself when you edit the terms, unless you set one.

## Maintenance mode

**Maintenance mode** closes every client area page, the login included, and shows your message (or sends clients to
another address, such as a status page). The admin panel is not affected, and you still see the client area while you
are signed in here. Online payments already under way are still recorded.

## Server problems

When a monitoring plugin says a server is down or in maintenance, its clients see a notice at the top of the client
area naming their services on it. It goes away by itself once the server is up again. Turn it off or write your own
message under Server problems.

## The public area

Settings → Client area → **Public area** opens parts of the client area to visitors who are not logged in, each off
until you switch it on:

- a **home page** with your welcome text, the domain search and what you sell;
- **domain search** and a **domain prices** page;
- the **public order page** (`/cart`): visitors fill a cart, then sign up or log in to place it;
- the **knowledge base** (articles can stay for clients only);
- **announcements** with an RSS feed;
- a **contact page** (`/contact`) with a **contact form** that opens a ticket the visitor follows at a private link (its
  email carries only that link, none of the visitor's words, and one address gets at most 3 an hour, so nobody can send
  others mail through it) and, with **Your contact details** on, your details beside it: the lines you tick from
  Settings → Company (address, phone, email, website, VAT number), opening hours and a text above the form in each
  language, and an **Open in maps** button for the address. The details work without the form too, and logged-in clients
  find the page under Support → Contact us, with Open a ticket;
- a **server status** page: each server's state and uptime by a public name, never its hostname or IP.

**Cookies** (Settings → Client area → Cookies, off by default) shows a cookie banner, links your cookie policy and runs
scripts such as analytics only after the visitor accepts. Search engines index the public pages unless you turn it off
(Settings → Client area → Search engines); the admin panel and signed-in pages are never indexed. Each language of a
page has its own address (`?lang=el`; none for the default language), and search engines find them all in
`/sitemap.xml`, which `/robots.txt` points to. Give that sitemap address to Google Search Console. In a subfolder install,
search engines only read the `robots.txt` at the domain's root, so submit the sitemap by hand there.

Next: [Support](11-support.md).
