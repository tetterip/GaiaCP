# Automation

Most of GaiaCP's work happens on its own, from the cron line that runs `php artisan schedule:run` every minute. If cron
stops, nothing is billed or reminded: the dashboard's Needs attention says so and the super admins' bell rings.
Settings → System → **System health** shows when cron last ran and when each daily job last ran.

Everything automatic is written to the **Activity log** (System → Activity log), and every job checks the current
state before it acts, so a job that runs twice, or again after a failure, never does the same thing twice.

## The daily run

At the time set in Settings → Billing → **Automation** → Daily run at (09:00 by default, in your timezone), GaiaCP:

1. creates the payment notices that are due (Settings → General → Create payment notices … days before) and emails
   them;
2. sends the payment reminders and overdue notices on their days (see [Billing](06-billing.md));
3. pays notices from client credit, when that is on;
4. sends the Upcoming Domain Renewal Notice, adds late fees to expired domains and renews free domains;
5. **suspends** hosting that is overdue by **Suspend hosting after** days (empty = never). Recording the payment
   unsuspends it;
6. **terminates** services set to end: a service with **Auto-terminate at the end of the cycle** (Automation section
   of the service) gets no more notices and is terminated on its next due date. For a domain this switch is shown as
   Auto renew off;
7. **cancels unpaid orders** after Cancel unpaid orders after days (empty = never);
8. emails the admins a **daily summary**: new and overdue payment notices and domain syncs that changed or failed.
   Nothing to report, no email.

The same page has **BCC every client email to** (a copy of each client email for your records), the **Price change
notice** days, the **Usage warning** share (hosting accounts near their disk or bandwidth limit get an email
suggesting a bigger package, once a month), how many **Messages to clients** go out a minute, and which admin emails
are sent (new order, new client, change requests, cancellation and upgrade requests).

## Other scheduled jobs

| When | What |
|---|---|
| Every minute | Queued messages to clients, webhooks, Slack / Discord / Telegram messages, the support mailbox |
| Every 5 minutes | Server status from the monitoring service |
| Hourly | SSL certificates, IP blacklists |
| 00:20 | Quotes past their date marked Expired |
| 03:30 | Old module and email log rows deleted (Settings → Logs → Logging) |
| 04:00 | Domains' expiry dates and nameservers read from the registrars |
| 04:30 | Servers compared with GaiaCP (when switched on) |
| 05:00 | Site checks |
| 06:00 | Hosting usage warnings |
| 07:00 | Support tickets waiting on the client closed |
| 08:00 | Lead follow-ups in the bell |

Backups run at the time set under Settings → System → Backups.

## Previewing before it happens

From SSH you can see what a job would do, without doing it:

```
php artisan gaiacp:send-reminders --dry-run            # payment notices created and emailed, domain notices
php artisan gaiacp:suspend-overdue --dry-run           # hosting that would be suspended
php artisan gaiacp:terminate-scheduled --dry-run       # services that would be terminated
php artisan gaiacp:cancel-unpaid-orders --dry-run      # orders that would be cancelled
php artisan gaiacp:daily-summary --dry-run             # the admins' summary, and who gets it
```

Add `--date=2026-12-01` to the first four to see a day ahead.

## Notifications

GaiaCP tells you what needs you in several ways; pick the ones that suit you:

- **The bell** (top bar): new orders, payments by card, tickets and replies, servers down and up, failed panel and
  registrar calls, requests from clients and more. Each admin sees only the areas they can open and never their own
  actions; turn it off in Profile.
- **Push notifications**: the bell's notifications on your phone or computer (Profile → Push notifications).
- **System emails**: per admin, with Receive system emails on their user.
- **Slack**, **Discord** and **Telegram** (plugins, Settings → Email & notifications): the events you pick posted to
  a channel or chat. The Telegram bot can also answer `/owed`, `/today`, `/tickets` and `/servers`.
- **Webhooks**: the same events as signed JSON to your own scripts, Zapier, Make or n8n; see
  [Plugins and integrations](13-plugins-and-integrations.md).

Next: [The client area](10-client-area.md).
