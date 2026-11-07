# Trying GaiaCP with Docker

`docker compose up` runs GaiaCP on your own machine with a MariaDB database and the scheduler, installed and ready
to log in to. It is meant for trying GaiaCP and for development; a business on shared hosting installs the release
zip instead ([installation.md](installation.md)).

## Start it

You need Docker with Compose (Docker Desktop, or Docker Engine on Linux).

```bash
git clone https://github.com/tetterip/GaiaCP.git
cd GaiaCP
docker compose up -d --build
```

The first build takes a few minutes (PHP extensions and the Composer libraries). Then open
**http://localhost:8080/admin** and log in as `admin@example.com` / `change-me-now`, and follow the setup guide on
the dashboard. The client area is at http://localhost:8080.

## What runs

| Container | What it does |
|---|---|
| `app` | Apache with PHP 8.3 and GaiaCP. On the first start it installs GaiaCP (`gaiacp:install`) with the details in `docker-compose.yml`; after a new image it runs the migrations. |
| `scheduler` | `php artisan schedule:work`: the cron job (reminders, the daily run, queued emails, monitoring). It waits until the `app` container has installed GaiaCP. |
| `db` | MariaDB 10.11. |

Two volumes keep the data between restarts: `storage` (uploads, logs, backups, and the `.env` file, which the
image links to `storage/.env`) and `database`.

## Settings

Change them in `docker-compose.yml` before the first start:

- `GAIACP_ADMIN_EMAIL`, `GAIACP_ADMIN_PASSWORD`: the first admin. Leave `GAIACP_ADMIN_EMAIL` empty to install from the
  web installer at `/install` instead (database host `db`, the user and password of the `db` service; the setup code
  is in the volume: `docker compose exec app cat storage/app/private/install-code.txt`).
- `GAIACP_COMPANY`, `GAIACP_TIMEZONE`, `GAIACP_COUNTRY`: what the installer asks for; all can be changed later in
  Settings.
- `DB_PASSWORD` and `MARIADB_PASSWORD` must match; change both, and `MARIADB_ROOT_PASSWORD`.
- `MAIL_MAILER: log` writes emails to `storage/logs/laravel.log`; set a mail server under Settings → Mail to send them.
- `APP_URL`: the address you open; change it and the port (`8080:80`) together.

## Day to day

```bash
docker compose logs -f app                      # what the web container says
docker compose exec -u www-data app php artisan gaiacp:health
docker compose down                             # stop (the data stays)
docker compose down -v                          # stop and delete everything, to start over
```

To update, pull the new code and build again (`git pull && docker compose up -d --build`); the migrations run when
the `app` container starts. The **Update** button under Settings → System → Updates changes the files inside the
container only, which a new container loses, so update the image instead.

Plugins uploaded from the panel are kept in the container too: copy their folders into `plugins/` and build again to
keep them in the image.

## Limits

- One machine, no HTTPS: put a reverse proxy (Caddy, Traefik, nginx) in front for a public address, and set
  `APP_URL` to it.
- System health shows *Private files: not checked*, because from inside the container the site's own address is not
  reachable; the image serves only `public/`, so `.env` and `storage/` are never reachable from outside.
