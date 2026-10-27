# Contributing to GaiaCP

Thank you for helping. GaiaCP is a hosting billing and provisioning panel; it handles money and server passwords,
so changes are small, tested and easy to review.

## Before you start

- **Bugs:** open an issue with the steps to reproduce it, what you expected and what happened, and the GaiaCP version
  (shown in the admin footer). Security issues go to [SECURITY.md](SECURITY.md) instead, never to a public issue.
- **Features:** open an issue first, so we can agree on the shape before you spend time on it. Integrations (a server
  panel, a registrar, a payment method, a captcha, a monitoring service…) are plugins: see
  [docs/writing-plugins.md](docs/writing-plugins.md).
- **Out of scope:** GaiaCP never charges cards itself and doesn't issue legal invoices;
  [docs/features.md](docs/features.md) lists what it does and doesn't do.

## Setting up

PHP 8.3+ and Composer; SQLite is enough for development.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed   # demo data; log in at /admin as admin@example.com / password
php artisan serve
```

There is no npm step: the panels use Filament's own assets.

## Making a change

- Read the file in `docs/architecture/` for the area you change (`stack.md`, `code-layout.md`, `provisioning.md`);
  `CLAUDE.md` sums up the project rules and the domain rules (money in integer cents, every automated action logged and
  safe to re-run, secrets stored encrypted and never sent back to the browser, and so on).
- **Add a test with every feature or bug fix.** Tests never reach real servers: `Tests\TestCase` blocks stray HTTP
  requests; use the fake panels (`Tests\FakesWhm`, `FakesPlesk`, `FakesHestia`, `FakesEasy`) or `Http::fake()`.
- **Texts:** every text in the panels goes through `__()` with the English text as the key, values as
  `:placeholders`. `LocalesTest` fails on a text missing from `lang/el.json`: list them with
  `php artisan gaiacp:translations el`, add them to that file, and say in the pull request if you couldn't translate
  them (someone else will).
- **Plugins:** changing a plugin means bumping its `plugin.json` version and adding a line to its `CHANGELOG.md`.
- **Settings:** a new editable setting needs its default in `config/gaiacp.php` (or the plugin's `config.php`), its key
  in `Settings::KEYS` (or the plugin's `settingKeys()`) and a field on exactly one Settings page.
- Add a line under `## [Unreleased]` in `CHANGELOG.md` for anything a user would notice.

## Before you open a pull request

```bash
./vendor/bin/pint            # code style (Laravel preset)
php artisan test             # the whole suite, in-memory SQLite
```

Open the pull request against `main`, describe what changes for the person using GaiaCP (before / after), and add
screenshots for visible changes. Keep one change per pull request.

## Translations

`en` and `el` ship with GaiaCP. To add a language to the code, run `php artisan gaiacp:translations <code> --write`
(for example `de`), translate `lang/<code>.json` and open a pull request; plugins have their own `lang/` folder
(`--plugin=<Folder>`). Administrators can also upload a translation under Settings → Languages without changing code.

## Licence

By contributing you agree that your contribution is licensed under the GNU General Public License, version 3 or any
later version, like the rest of GaiaCP (see [LICENSE](LICENSE)).

Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).
