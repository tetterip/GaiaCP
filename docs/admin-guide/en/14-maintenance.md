# Keeping GaiaCP healthy

## Backups

Settings → System → **Backups** makes a zipped copy of the database and the uploaded files, daily or weekly at a set
time, and keeps the newest few. Give the zip a **password** (AES-256): the backup holds every client's details. Each backup can be copied
to an **FTP / FTPS** server and **emailed** to an address. **Back up now**, **Download latest** and **Test FTP** are on
the page; `php artisan gaiacp:backup` does the same from SSH.
**Test FTP** logs in, opens the folder and writes and deletes a small file there, so a folder the FTP account
cannot write to shows up then and not in the night's backup. When a copy fails, the bell and the page show the FTP
server's whole reply.

Logs go into the backup empty unless you switch on **Include logs**: the module log, the email log (so the clients'
Email history too), sessions, cache and queued jobs. They are not needed to restore GaiaCP, and the module log alone
can be most of the database while it is on.

**Include files** (on by default) puts the uploaded files in the zip too: the company logo, page pictures, ticket
attachments, client downloads, SSL files, uploaded translations and the plugins you added yourself. GaiaCP's own code is
not in it: you get that again by installing GaiaCP.

A zip with a password also carries the `APP_KEY` from `.env`, which decrypts the stored server, registrar and other
passwords, so it is all you need to restore. A zip without a password leaves the key out (a backup found by someone
should not open those passwords too), and its email reminds you: keep a copy of `.env`'s `APP_KEY` somewhere safe,
because a backup restored without it loses those passwords. Every zip has a `README.txt` that says what is in it and
how to restore it.

Keep a copy away from the server GaiaCP runs on.

### Restoring a backup

1. On the new server (or the old one), install GaiaCP the same version or newer, with an empty database of the same
   kind as the backup's (MySQL / MariaDB or SQLite). An install that is already running works too: its data is replaced.
2. Upload the backup zip and run, from SSH or a Plesk / cPanel scheduled task run once:
   `php artisan gaiacp:restore /path/to/gaiacp-backup-….zip --password="the zip password"`.
   It shows what the zip holds and asks before it goes on (`--force` doesn't ask). Then it switches to maintenance
   mode, replaces the whole database, writes the files to their places (files that aren't in the backup stay), sets
   `APP_KEY` in `.env` when the zip has it, applies the database changes of a newer GaiaCP and comes back.
3. Sign in again. If the zip had no `APP_KEY`, put your saved one in `.env`; without it, enter the passwords of your
   servers, registrars and other integrations again.

Without SSH or scheduled tasks: open the zip with 7-Zip or WinRAR, import the `.sql` file into the empty database with
phpMyAdmin, copy the `files/` folder's contents into the GaiaCP folder, set `APP_KEY` in `.env` from `app-key.txt`,  and, when
the backup is from an older GaiaCP, run `php artisan migrate --force` once (a scheduled task does it too) for the newer
database changes.

A wrong password, or a zip that isn't a GaiaCP backup, stops the restore before anything changes.

## Updates

Settings → System → **Updates** shows your version, the latest release and what changed. **Update** downloads the
release and checks it (that it arrived whole and that it is signed by GaiaCP's release key, so a
changed release is never installed), switches to maintenance mode, backs up the database, swaps in the new code (your `.env`,
`storage/`, your own plugins and anything you added to `public/` stay), runs the database changes and comes back. If a
step fails after the swap, the old code is put back, and the database is restored from the backup it just took, so a
half-done database change never stays behind. Only if that restore fails too does the site stay in maintenance mode:
the activity log then names the backup to restore with `php artisan gaiacp:restore` (see Restoring a backup). From SSH:
`php artisan gaiacp:update` (`--check` only looks). Read each release's notes before you update: GaiaCP is still in
beta, and things can change between versions.

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

### Monitoring from outside

System health can only warn you while GaiaCP runs. If the server, PHP or cron stops, nothing inside GaiaCP can tell
you. Settings → Automation → **Monitoring from outside** fixes that: give a **Ping URL** from a heartbeat monitor
(Uptime Kuma push monitor, Healthchecks.io, Better Stack heartbeat, Cronitor) and GaiaCP calls it every 5 minutes
while all is well. Set the monitor to expect a ping every 5 minutes, with a few minutes of grace: when the pings stop,
it alerts you.

When a daily job failed in the last day, or the last backup failed, GaiaCP calls the **Ping URL on failure** instead,
with `{message}` in it replaced by what failed (Uptime Kuma: `…/api/push/<token>?status=down&msg={message}`;
Healthchecks.io: the ping URL with `/fail` at the end). Without one, it sends no ping, so the monitor alerts all the
same. Both URLs are stored encrypted, since anyone who has one can ping it. The page shows the last ping, and **Send a
ping now** tries it at once; `php artisan gaiacp:heartbeat --dry-run` shows what would be sent.

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

Super admins also find **Clean up** at the bottom of Logging, to make the database or the disk smaller now:

- one button per log (module, email, activity, bell notifications) with its number of entries, to delete the entries
  older than a day you choose. The activity log always keeps its last 30 days, and each clean up is itself recorded
  in it;
- **Delete files of closed tickets**: the files attached to tickets closed before a day. The messages stay and name
  each file as deleted. Settings → Support → **Delete files of closed tickets after** does the same every night;
- **Empty the cache**: the stored cache and the compiled page templates, for example after changing files on the
  server by hand. Both fill again by themselves.

What is deleted is gone for good, but backups made before still have it.

## When something goes wrong

- **Nothing was emailed or billed today**: check System health for cron, then the Email log.
- **A server or registrar call failed**: it waits in Services → Module queue (see
  [Automation](09-automation.md)), and the error is on the service's Activity tab. Switch on the module log, Retry,
  and read what the panel answered.
- **A client says they paid**: look under Billing → Payments and on the payment notice. A Stripe or PayPal payment that
  didn't fit its notice any more is listed in the daily summary.
- **The client area must close for a while**: Settings → Client area → Maintenance mode.

Next: [Moving from WHMCS](15-whmcs-import.md).
