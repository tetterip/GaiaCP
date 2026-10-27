# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.1.3] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.1.2] - 2026-10-25

- An email the receiving mail server marks as forged (DMARC fail, or SPF fail without a passing DKIM signature, in
  Authentication-Results) is from an unknown sender: it joins no ticket and opens none for the client of that address

## [1.1.1] - 2026-10-24

- Its settings page is under Settings → Support (Settings regrouped)

## [1.1.0] - 2026-10-24

- Receive by webhook: a mail provider (Mailgun, SendGrid, Postmark…) posts each email as raw MIME to a secret address
  (`POST /webhooks/ticket-mail/{token}`), and it becomes a ticket or an answer like one read over IMAP. Settings →
  Email to ticket makes the address (shown once, stored encrypted) or turns it off

## [1.0.0] - 2026-10-23

- Email to ticket: reads a support mailbox over IMAP every minute (no PHP imap extension needed). An answer to a ticket
  email joins its ticket (found by the ticket's email headers or [T-000123] in the subject, only from the ticket's
  client or sender); any other email opens a ticket, in the department whose address it was sent to. Quoted earlier
  text is left out, allowed files are attached, automatic replies are ignored and each sender may open a limited
  number of tickets an hour. Email from someone who is not a client opens a ticket without a client (an admin links
  one under Change), or is ignored. Settings → Email to ticket, with Test connection.
