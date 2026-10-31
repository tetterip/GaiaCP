# Keeping GaiaCP healthy

## Backups

Settings → System → **Backups** makes a zipped copy of the database, daily or weekly at a set time, and keeps the
newest few. Give the zip a **password** (AES-256): the backup holds every client's details. Each backup can be copied
to an **FTP / FTPS** server and **emailed** to an address. **Back up now**, **Download latest** and **Test FTP** are on
the page; `php artisan gaiacp:backup` does the same from SSH.

Logs go into the backup empty unless you switch on **Include logs**: the module log, the email log (so the clients'
Email history too), sessions, cache and queued jobs. They are not needed to restore GaiaCP, and the module log alone
can be most of the database while it is on.

Keep a copy away from the server GaiaCP runs on, and keep a copy of `.env` somewhere safe: its `APP_KEY` decrypts the
stored passwords and tokens, so a backup restored without it loses them.

## Updates

Settings → System → **Updates** shows your version, the latest release and what changed. **Update** backs up the
database first, downloads the release and checks it (that it arrived whole and that it is signed by GaiaCP's release key, so a
changed release is never installed), switches to maintenance mode, swaps in the new code (your `.env`,
`storage/`, your own plugins and anything you added to `public/` stay), runs the database changes and comes back. If a
step fails after the swap, the old code is put back; the backup is there for the database. From SSH:
`php artisan gaiacp:update` (`--check` only looks). Read each release's notes before you update: GaiaCP is still in
alpha, and things can change between versions.

GaiaCP looks for a new release once a day. When one is out, super admins get a bell notification (once per version)
and the daily summary email mentions it; a copy deployed from Git is told to deploy instead. `GAIACP_UPDATE_CHECK=false`
in `.env` turns the daily look off. GitHub answers 60 checks an hour per server address (shared with other sites on
the same address); when that runs out, Updates says when to try again, and `GAIACP_UPDATE_TOKEN` (a GitHub token)
raises the limit.

An install deployed from Git has no Update button: it is updated by deploying.

## System health

Settings → System → **System health** checks the PHP version and extensions, the configuration, the database, storage,
that private files such as `.env` can't be read from the web, cron and when each daily job last ran, mail, and the
connections to your servers and registrars. Anything marked as a problem says what to do. `php artisan gaiacp:health`
prints the same (add `--connections` to test the servers and registrars too) and fails when something is wrong, so a
monitoring script can run it.

**Biggest tables** at the bottom shows which tables take the most room, and which ones backups leave empty. When the
database or the backups grow, look there first: usually it is the module log left on.

## Logs

Settings → **Logs**:

- **Module log** (off until you switch it on under **Logging**): every request to a server panel, registrar or other
  service and what it answered, with passwords, tokens and EPP codes masked. Switch it on while you look into a
  problem.
- **Email log**: every email sent to clients and to you, with its text, and the ones that failed with the reason.
- **Error log**: Laravel's log of errors and warnings, to read, download or clear. Include it when you report a bug.
- **Failed jobs**: background jobs that failed every try, with their error, to retry or delete.

**Logging** sets how many days the module log (30) and sent emails (90) are kept, and also the activity log (kept for
ever unless you set days, at least 30) and the bell notifications (90, read or not). Older rows are deleted every
night.

## When something goes wrong

- **Nothing was emailed or billed today**: check System health for cron, then the Email log.
- **A server or registrar call failed**: the error is on the service's Activity tab and in Needs attention. Switch on
  the module log, try again, and read what the panel answered.
- **A client says they paid**: look under Billing → Payments and on the payment notice. A Stripe or PayPal payment that
  didn't fit its notice any more is listed in the daily summary.
- **The client area must close for a while**: Settings → Client area → Maintenance mode.

Next: [Moving from WHMCS](15-whmcs-import.md).
