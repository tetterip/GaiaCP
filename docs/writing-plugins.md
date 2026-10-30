# Writing a GaiaCP plugin

A plugin adds one thing to GaiaCP: a server panel, a domain registrar, a payment method, a captcha, a monitoring
service, an admin theme or any other integration. GaiaCP's own plugins in `plugins/` are complete examples
(`plugins/UptimeRobot` is a small one). New to it? [plugin-tutorial.md](plugin-tutorial.md) builds a complete
plugin step by step.

## The folder

```
MyPlugin/
├── plugin.json             {"version": "1.0.0", "requires": "0.1.0", "class": "Plugins\\MyPlugin\\MyPluginPlugin"}
├── CHANGELOG.md            "## [1.0.0] - 2026-10-18" and what changed, one section per version
├── MyPluginPlugin.php      the class named in plugin.json
├── config.php              optional: dotted config key => defaults (values already in config win)
├── lang/el.json            optional: translations of its texts (English text => translation); an admin's uploads in Settings → Languages win over it
├── views/                  optional: Blade views, used as myplugin-id::name
└── database/migrations/    optional: its own tables, run on install and by every `php artisan migrate`
```

- The folder name is the namespace: classes are `Plugins\MyPlugin\...`, loaded from the folder (PSR-4).
- `plugin.json` needs `version` (x.y.z). `requires` is the oldest GaiaCP it runs on; on an older one it stays off.
  `class` defaults to `Plugins\<Folder>\<Folder>Plugin`.
- The class extends `App\Plugins\Plugin` and gives `id()` (lowercase, stable: it is stored in the database),
  `name()`, `description()` (through `__()`) and `category()` (Servers, Registrars, Monitoring, Payments,
  Integrations, AI or Themes; an AI service also puts its settings page in the `AI assistant` group). It implements an interface for what it adds, such as `App\Provisioning\ServerModule`,
  `App\Provisioning\RegistrarModule`, `App\Payments\PaymentModule`, `App\Captcha\CaptchaModule`,
  `App\Monitoring\MonitoringModule` or `App\Themes\ThemeModule`, and overrides the `Plugin` methods it needs:
  `settingsPage()`, `pages()`, `commands()`, `settingKeys()` / `encryptedSettingKeys()`, `clientRoutes()`,
  `webhookRoutes()`, `taxExemption()` (a client pays no tax, with the reason printed on their payment notices) and
  the others documented in `app/Plugins/Plugin.php`.
- To act on what happens in GaiaCP (a payment recorded, a service suspended, a ticket opened, …) a plugin lists the
  `App\Events\GaiaEvent::NAMES` it wants in `events()` (or `['*']`) and gets each one in `handleEvent(GaiaEvent $event)`,
  only while it is on and after the action is committed. `$event->name`, `$event->log` (the activity log row),
  `$event->payload()` (client, service and the notice, payment, order, ticket or server it is about, as plain data
  without secrets) and `$event->summary()` (one line). Keep it quick: anything slow (an HTTP call) is queued in its
  own table and sent by a scheduled command, as `plugins/Webhooks` does. An exception there is reported and never
  undoes the action. Outside plugins, `Event::listen(GaiaEvent::class, …)` gets them too.
- A server panel's provisioner may also implement the optional `App\Provisioning\OffersShortcuts` (client area
  Quick shortcuts), `ListsAccounts` (Servers → Compare with the server), `ControlsPower` (Start / Shut down / Restart
  for a machine, as `plugins/Proxmox` does), `InstallsCertificates` (validates and installs SSL certificates on a
  hosting account, as cPanel and Plesk do) and `LimitsUsernameLength`.
- A registrar may also implement `App\Provisioning\ChecksAvailability` (the client area's domain search asks it first),
  `EmailsEppCode`, `ManagesDomainContacts` (a domain's contact
  details), `ManagesChildNameservers` (private nameservers) and `ManagesRegistrarLock`; the domain screens offer each
  only when the registrar has it.
- Pages, routes and commands are registered whether the plugin is on or off, so they check
  `app(PluginManager::class)->enabled($plugin)` (pages in `canAccess()`).
- A settings page joins the Settings cluster with the group that fits from `SettingsCluster::GROUPS` (`$navigationGroup`,
  e.g. 'Integrations' for a monitoring service, 'Client area' for a sign-in or captcha) and a `$navigationSort` in
  that group's range (General 10s, Billing 20s, … Logs 100s).
- API clients add `App\Support\ModuleLogger::middleware()` so Settings → Logging → Module log can show their calls.
  Secrets are stored encrypted (`encryptedSettingKeys()`) and never sent back to the browser.

## Packaging and installing

Zip the folder (`zip -r MyPlugin-1.0.0.zip MyPlugin`; `plugin.json` may also be at the top of the zip). A super
admin uploads it under Settings → Plugins → Upload plugin. GaiaCP refuses a zip that has files outside its folder,
symbolic links, more than 2000 files or 50 MB unpacked, a PHP file that doesn't parse, a bundled plugin's name or a
`requires` newer than itself. It then moves the folder into `plugins/`, runs its migrations and lists it disabled.

To update, bump `version`, add its CHANGELOG entry and upload the new zip: only a newer version is accepted, the
old folder is kept in `storage/app/private/plugins/previous/` and put back if the migrations fail. Migrations must
have a `down()` so Uninstall → "Also delete its tables and settings" can remove them.

A plugin runs with GaiaCP's full access, so the upload asks for the admin's password. A host can turn uploads off
with `GAIACP_PLUGIN_UPLOADS=false` in `.env`; plugin folders copied into `plugins/` by hand still load (disabled
until enabled). GaiaCP updates keep the plugins it doesn't ship.

To list a plugin in the plugin catalogue (plugins.gaiacp.digital), send it as a pull request to the `gaiacp-plugins`
repository: its CONTRIBUTING.md says what `plugin.json` needs there and what the review looks at.
[plugin-catalogue.md](plugin-catalogue.md) describes the catalogue's format and checks.
