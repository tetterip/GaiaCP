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
- `APP_URL`: the address you open; change it and the port (`127.0.0.1:8080:80`) together. The port listens on this
  machine only, so nobody else on the network reaches the panel while it still has the passwords above; for a public
  address, see below.

To keep your own values out of `docker-compose.yml` (so a `git pull` never clashes with them), put them in a
`docker-compose.override.yml` next to it, which Compose reads on its own:

```yaml
services:
  app:
    environment:
      GAIACP_ADMIN_PASSWORD: a-long-password-of-your-own
      DB_PASSWORD: another-long-password
  scheduler:
    environment:
      DB_PASSWORD: another-long-password
  db:
    environment:
      MARIADB_PASSWORD: another-long-password
      MARIADB_ROOT_PASSWORD: a-third-long-password
```

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

## Backups

Settings → Backups works as on any install; the zips are kept in the `storage` volume
(`storage/app/private/backups`), so also send them to FTP or email there, or copy them out now and then:

```bash
docker compose exec -u www-data app php artisan gaiacp:backup
docker compose cp app:/var/www/html/storage/app/private/backups ./backups
```

To put one back (it replaces the database: admin guide → Keeping GaiaCP healthy → Restoring a backup):

```bash
docker compose cp gaiacp-backup-….zip app:/tmp/backup.zip
docker compose exec -u www-data app php artisan gaiacp:restore /tmp/backup.zip --password="the zip password"
```

## A public address with HTTPS

The image serves plain HTTP; give it a domain through a reverse proxy that takes care of the certificate. With Caddy,
in `docker-compose.override.yml` (point the domain's DNS at this machine first, and open ports 80 and 443):

```yaml
services:
  app:
    ports: !reset []
    environment:
      APP_URL: https://billing.example.com
      # The proxy's requests come from the Docker network: trust it for the visitor's address and https
      GAIACP_TRUSTED_PROXIES: 172.16.0.0/12
  scheduler:
    environment:
      APP_URL: https://billing.example.com
  caddy:
    image: caddy:2
    command: caddy reverse-proxy --from billing.example.com --to app:80
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - caddy:/data
    restart: unless-stopped
volumes:
  caddy:
```

Then `docker compose up -d`. The containers' `APP_URL` comes before the one in `storage/.env`. GaiaCP answers only
for the host of `APP_URL` (and its subdomains), so a request for any other name is refused.

## Limits

- One machine; HTTPS only through a proxy in front (above).
- System health shows *Private files: not checked*, because from inside the container the site's own address is not
  reachable; the image serves only `public/`, so `.env` and `storage/` are never reachable from outside.
