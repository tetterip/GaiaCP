# The plugin API

A plugin runs inside GaiaCP and can call any of its code, but only the classes listed here are a promise. Build on
these and a GaiaCP update won't break your plugin without warning. The list is kept in `App\Plugins\PluginApi::STABLE`.
A test checks that this page names every class in it, and that GaiaCP's own plugins use nothing outside it (or the
`INTERNAL` list below).

## The promise

- **What counts:** a listed class's public methods, constants and enum cases, with their arguments and what they return;
  for an interface, the methods it asks of your plugin; for a model, its relations and columns; for an event, the names in `GaiaEvent::NAMES` and the keys of `payload()`.
- **Adding is not breaking:** a new method on a class, a new optional argument, a new enum case, a new event or payload
  key, a new column can come in any release. Use `match` with a default arm on GaiaCP's enums.
- **A breaking change** (a method renamed or removed, an argument added without a default, a method added to an
  interface without a default implementation, a column or event removed) comes only in a minor release while GaiaCP
  is 0.x (0.7 → 0.8), and only in a major one from 1.0. It is listed under **Plugin API** in that release's
  `CHANGELOG.md` entry with what to change. Where it can, the old way keeps working, marked `@deprecated`, until the next
  minor release.
- **Your side:** set `requires` in `plugin.json` to the oldest GaiaCP you tested on. GaiaCP keeps a plugin that requires
  a newer version switched off.

Everything else (other classes in `app/`, the Blade views, the database tables you reach without a model, the
Filament pages and resources) can change in any release. If you need something that isn't listed, open an issue: it
can be added.

## The classes

### Plugin basics

- `App\Plugins\Plugin`: the class your plugin extends ([writing-plugins.md](writing-plugins.md)).
- `App\Plugins\PluginManager`: `enabled($plugin)`, `find($id)`, `directory()` and the module lookups (`serverModule()`, `registrarModule()`, `paymentModule()`, …).
- `App\Events\GaiaEvent`: what happens in GaiaCP, given to `handleEvent()`.
- `App\Models\ActivityLog`: `ActivityLog::record()` for what your plugin did.
- `App\Support\ModuleLogger`: `ModuleLogger::middleware()` on your API client, for the module log.
- `App\Models\Concerns\StoresTimesInUtc`: use it on every model of your plugin. Times are stored in UTC and read in
  the timezone of Settings → General → Region; plain dates (`date` casts) stay as they are.
- `App\Support\StoredTime`: `StoredTime::parse()` turns a time read without a model (`DB::table()`, `min()`, `max()`)
  from UTC into local time. A Carbon given to a query is converted to UTC for you; give a plain date column a date
  string (`toDateString()`) or use `whereOnDate()`.

### Server panels

- `App\Provisioning\ServerModule` and `App\Provisioning\HostingProvisioner`: the module and the provisioner it returns.
- `App\Provisioning\AccountState`, `App\Provisioning\AccountUsage`, `App\Provisioning\PanelAccount`: what the
  provisioner answers.
- `App\Provisioning\ProvisioningException`: thrown when the panel refuses or can't be reached.
- Optional: `App\Provisioning\OffersShortcuts`, `App\Provisioning\ListsAccounts`, `App\Provisioning\ControlsPower`,
  `App\Provisioning\DescribesMachine` (with `App\Provisioning\MachineDetails`), `App\Provisioning\InstallsCertificates`,
  `App\Provisioning\LimitsUsernameLength`.

### Domain registrars

- `App\Provisioning\RegistrarModule`, `App\Provisioning\DomainRegistrar` and `App\Provisioning\ManualRegistrar`.
- `App\Provisioning\DomainContact`, `App\Provisioning\TldPrice`.
- Optional: `App\Provisioning\ListsTldPrices`, `App\Provisioning\ChecksAvailability`, `App\Provisioning\EmailsEppCode`,
  `App\Provisioning\ManagesDomainContacts`, `App\Provisioning\ManagesChildNameservers`,
  `App\Provisioning\ManagesRegistrarLock`.
- `App\Support\DomainName` (names and TLDs) and `App\Support\EppPhone` (phone numbers as registries want them).

### Payments, captcha, monitoring, SSL certificates, chat, AI and themes

- `App\Payments\PaymentModule`; record what the gateway confirmed with `App\Actions\RecordInvoicePayment`, and send the
  client back to `App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource::getUrl('view', …, panel: 'client')`.
  Optional: a public `instructionsFor(App\Models\Invoice $invoice): ?string` gives the instructions for one payment
  notice (its page and emails) in place of `instructions()`, e.g. Bank transfer adds the notice number as the reference.
  For a payment GaiaCP can't see (a transfer), `App\Actions\PaymentClaims` takes the client's "I've paid": `canClaim()`,
  `claim($invoice, $methodKey)` (bell + admins' email, nothing recorded), `waiting()`. When several payment plugins
  offer a button for a notice, the client area shows them under one **Pay online** menu.
- `App\Captcha\CaptchaModule`.
- `App\Monitoring\MonitoringModule`, `App\Monitoring\ServerMonitoring`, `App\Monitoring\Monitor`,
  `App\Monitoring\MonitorStatus`, `App\Monitoring\MonitoringException`.
- `App\Certificates\CertificateModule`, `App\Certificates\CertificateOrder`, `App\Certificates\CertificateProduct`,
  `App\Certificates\CertificateState`.
- `App\Chat\ChatPlugin`, `App\Chat\ChatLine`, `App\Chat\ChatSender`, `App\Chat\ChatSettingsPage`.
- `App\Ai\AiModule`, `App\Ai\Prompt`, `App\Ai\Answer`, `App\Ai\AiException`; for a service with an OpenAI-style chat
  completions API, `App\Ai\ChatCompletionsPlugin` and `App\Ai\ChatCompletionsSettings` do most of it.
- `App\Themes\ThemeModule`.

### Settings pages and the admin panel

- `App\Filament\Clusters\Settings\SettingsCluster` (its `GROUPS`), `App\Filament\Clusters\Settings\SettingsFormPage`,
  `App\Filament\Clusters\Settings\PaymentMethodPage`, `App\Filament\Clusters\Settings\PluginSwitch`,
  `App\Filament\Clusters\Settings\SettingsCard`.
- `App\Filament\Concerns\LimitedToAdminArea` with `App\Enums\AdminArea`, and `App\Filament\Concerns\TranslatesPageLabels`.
- `App\Providers\AppServiceProvider`: only `dateFormat()`, the date format of Settings → General → Region.

### Records and values

- Models: `App\Models\Client`, `App\Models\Service`, `App\Models\Server`, `App\Models\Registrar`,
  `App\Models\Product`, `App\Models\Order`, `App\Models\Invoice` (a payment notice), `App\Models\InvoiceItem`,
  `App\Models\Payment`, `App\Models\User`. Read them freely; change them through GaiaCP's actions where one exists
  (recording a payment, suspending a service), so the activity log, emails and events follow.
- Enums: `App\Enums\ServiceType`, `App\Enums\ServiceStatus`, `App\Enums\BillingCycle`, `App\Enums\AutoSetup`,
  `App\Enums\ClientStatus`, `App\Enums\InvoiceStatus`, `App\Enums\DomainRegistrationType`, `App\Enums\UserRole`.
- `App\Support\Money` (integer cents), `App\Support\Currencies`, `App\Support\Locales`, `App\Support\Region`.

## Internal

GaiaCP's own plugins also use a few classes that are not part of the API, because they ship and change together with
GaiaCP: the ticket, sign-up and client area actions, the client and service resources, the AI and support settings
pages, `App\Support\Settings`, `App\Support\Tax`, `App\Support\ClientFields`, `App\Support\Demo` and others listed in
`App\Plugins\PluginApi::INTERNAL`. Don't copy those uses into a plugin of your own.
