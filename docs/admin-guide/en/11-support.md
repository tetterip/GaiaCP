# Support

## Tickets

Support → **Tickets** is where clients' questions arrive. Clients open tickets from the client area (department, the
service it is about, priority, files) and answer them there; visitors can use the contact form when it is on; and with
the Email to ticket plugin, emails become tickets too. You can open one for a client from their page (Tickets tab).

The tabs show **Waiting for us**, **Assigned to me**, **Waiting for the client**, **On hold** and **Closed**. A ticket's
page shows the conversation beside the client's services and what they owe. There you can:

- **Reply**, starting from a canned reply if you like, and choose the status after it;
- **Add note**: an internal note the client never sees;
- **Change** the department, priority, who it is assigned to, or the client;
- **Close** it.

Whoever wrote last sets the status: a client's message makes it "Awaiting reply", yours makes it "Answered", and a
reply opens a closed ticket again. The client is emailed each answer; the assigned admin, or every admin with the
Support area, gets an email and the bell for new tickets and replies. A ticket waiting on the client closes by itself
after Settings → Support → **Close tickets after** (7 days by default).

Settings → Support also holds:

- **Ticket departments**: where tickets go (Technical support, Billing…), with their own reply address, and whether
  clients can pick each one;
- **Canned replies**: ready answers with placeholders such as `{client_first_name}` and `{ticket_number}`;
- who is emailed, the files clients may attach (size and types), and whether clients may set the priority.

The dashboard's Support card lists the tickets waiting for an answer.

### Email to ticket

The **Email to ticket** plugin reads your support mailbox over IMAP every minute: answers to ticket emails join their
ticket, and new emails open one (from someone who isn't a client, a ticket without a client that you can link later).
Create the mailbox in your mail server and fill in its details under Settings → Support → Email to ticket. A mail
provider (Mailgun, SendGrid, Postmark) can post the emails to a secret webhook address instead. An email your mail
server marks as forged (DMARC or SPF failed) counts as from someone who isn't a client, whatever address it shows.

## Knowledge base

Support → **Knowledge base** holds help articles in categories (Settings → Support → Knowledge base categories), each
with a text per language in Markdown. Published articles show in the client area with a search that ignores accents
and case, and "Was this helpful?" counts. While a client writes a new ticket, the articles that match its subject show
under it, which answers many questions before they are asked. Settings → Support switches the knowledge base off.

## Announcements

Support → **Announcements** are news for clients (and visitors, if the public area shows them): planned work, new
products, holiday hours. Each has a text per language and a publish date; leave it empty to keep a draft. Mark one
**clients only** to keep it from visitors.

## Messages to clients

Main → **Messages to clients** emails many clients at once, or shows a message at the top of their client area Home
between two dates (or both). Write one text per language and pick who gets it by servers, products, domain endings,
service types and language; the count shows how many will. **Send test to me** first. The text is Markdown; HTML in it
goes into the email, but the client area shows none.

The emails go out a few a minute (Settings → Automation → Messages per minute) and each recipient gets it once; the
Recipients list shows who did. A message is either:

- **Informational** (news, offers): it ends with an unsubscribe link, and clients who turned off informational emails
  don't get it;
- **About their services** (planned work, a server move): everyone matched gets it.

Next: [Admins and security](12-admins-and-security.md).
