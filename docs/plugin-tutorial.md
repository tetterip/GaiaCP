# Tutorial: your first plugin

This walks through a complete, small plugin: **ntfy**, which sends GaiaCP's new orders and payments to your phone
through [ntfy](https://ntfy.sh) (a free push notification service you can also host yourself). On the way it uses
most of what plugins have: a settings page with an On switch, a secret stored encrypted, GaiaCP's events, an HTTP call
in the module log, Greek texts, and a zip to upload. The reference for everything a plugin can do is
[writing-plugins.md](writing-plugins.md); GaiaCP's own plugins in `plugins/` are more examples.

You need a GaiaCP to try it on (a local one: `composer install`, `php artisan migrate:fresh --seed`,
`php artisan serve`, then `/admin` as admin@example.com / password) and the ntfy app on your phone, subscribed to a
topic name only you know.

## 1. The folder

A plugin is a folder in `plugins/`. Its name is the PHP namespace: files in `plugins/Ntfy/` are classes in
`Plugins\Ntfy`. Create:

```
plugins/Ntfy/
├── plugin.json
├── CHANGELOG.md
├── config.php
├── NtfyPlugin.php
├── NtfySettings.php
└── lang/el.json
```

`plugin.json` has the plugin's version and the oldest GaiaCP it runs on (on an older one it stays off). The class
defaults to `Plugins\<Folder>\<Folder>Plugin`:

```json
{
    "version": "1.0.0",
    "requires": "0.1.0"
}
```

`CHANGELOG.md` gets a section for every version; Settings → Plugins shows it:

```markdown
# Changelog

## [1.0.0] - 2026-10-25

- First version: new orders and payments as ntfy notifications.
```

## 2. Its settings

`config.php` gives the defaults of its settings. GaiaCP merges them into its config, and what an admin saves on the
settings page wins over them:

```php
<?php

// Merged into config by GaiaCP (values already in config win).
return [
    'gaiacp.ntfy' => [
        'url' => null,   // https://ntfy.sh/my-secret-topic
        'token' => null, // access token for a protected topic, stored encrypted
    ],
];
```

## 3. The plugin class

The class says who the plugin is and what it adds. `id()` is stored in the database, so it never changes.
`settingKeys()` are the settings its page saves, `encryptedSettingKeys()` those stored encrypted (and never sent back
to the browser: the field stays blank and keeps the saved value). `moduleLogs()` names its entry in Settings → Logging
→ Module log, where `ModuleLogger::middleware()` writes each call (the token is replaced with `***`).

`events()` lists the GaiaCP events it wants (any of `App\Events\GaiaEvent::NAMES`, or `['*']`); `handleEvent()` gets
each one while the plugin is on, after the action is saved. It runs while the admin or the client is still waiting
for the page, so the HTTP call is `defer()`red until the response is sent. (A plugin that sends a lot, or must retry,
keeps a queue in its own table and sends it from a scheduled command, as `plugins/Webhooks` does.) An exception in
`handleEvent()` is reported and never undoes the payment or the order.

```php
<?php

namespace Plugins\Ntfy;

use App\Events\GaiaEvent;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;

use function Illuminate\Support\defer;

/** New orders and payments as push notifications through ntfy (ntfy.sh or your own server). */
class NtfyPlugin extends Plugin
{
    public function id(): string
    {
        return 'ntfy';
    }

    public function name(): string
    {
        return 'ntfy';
    }

    public function description(): string
    {
        return __('Sends new orders and payments to your phone through ntfy.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return NtfySettings::class;
    }

    public function pages(): array
    {
        return [NtfySettings::class];
    }

    public function settingKeys(): array
    {
        return ['ntfy.url', 'ntfy.token'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['ntfy.token'];
    }

    public function moduleLogs(): array
    {
        return ['ntfy' => 'ntfy'];
    }

    public function events(): array
    {
        return ['order.placed', 'payment.recorded'];
    }

    public function handleEvent(GaiaEvent $event): void
    {
        $url = config('gaiacp.ntfy.url');

        if (blank($url)) {
            return;
        }

        $title = $event->summary();

        // After the response is sent, so the admin or client never waits for ntfy
        defer(fn () => $this->send($url, $title));
    }

    public function send(string $url, string $message): bool
    {
        $token = config('gaiacp.ntfy.token');

        $response = Http::timeout(5)
            ->withMiddleware(ModuleLogger::middleware('ntfy', 'notify', array_filter([$token])))
            ->when(filled($token), fn ($http) => $http->withToken($token))
            ->withBody($message, 'text/plain')
            ->post($url);

        return $response->successful();
    }
}
```

`$event->summary()` is one line such as "Payment recorded: Payment of €60.00 recorded; next due date …". For more,
`$event->payload()` has the client, the service and the payment notice, payment, order, ticket or server the event is
about, as plain data without passwords.

## 4. The settings page

A settings page is a Filament page in the Settings cluster. `SettingsFormPage` loads and saves the `keys()` (the
encrypted ones too) and `PluginSwitch` puts the plugin's On switch on top, so it can be switched on where it is set
up. `$navigationGroup` is one of the Settings groups (`App\Filament\Clusters\Settings\SettingsCluster::GROUPS`). A
page without `adminArea()` is for super admins only; return an `App\Enums\AdminArea` to open it to the admins who
have that area.

Every text goes through `__()` with the English text as the key, so it can be translated. Field labels are
translated already.

```php
<?php

namespace Plugins\Ntfy;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → ntfy: the topic's URL and its token. Super admins only (no adminArea()). */
class NtfySettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static ?int $navigationSort = 69;

    protected static ?string $title = 'ntfy';

    protected static ?string $slug = 'ntfy';

    public static function settingsDescription(): string
    {
        return __('New orders and payments as notifications on your phone.');
    }

    public static function pluginId(): string
    {
        return 'ntfy';
    }

    public static function keys(): array
    {
        return (new NtfyPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(function () {
                    $url = config('gaiacp.ntfy.url');

                    $sent = filled($url) && (new NtfyPlugin)->send($url, __('A test from GaiaCP'));

                    $sent
                        ? Notification::make()->success()->title(__('Sent'))->send()
                        : Notification::make()->danger()->title(__('Not sent: check the URL and token, then save'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('ntfy.url')
                            ->label('Topic URL')
                            ->url()
                            ->placeholder('https://ntfy.sh/my-secret-topic')
                            ->helperText(__('Subscribe to the same topic in the ntfy app on your phone.')),
                        TextInput::make('ntfy.token')
                            ->label('Access token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.ntfy.token')) ? '••••••••' : 'tk_…')
                            ->helperText(__('Only for a protected topic. Stored encrypted; leave blank to keep the saved one.')),
                    ]),
            ]);
    }
}
```

## 5. Greek (and other languages)

`lang/el.json` holds its texts in Greek, English text => translation. GaiaCP's own texts and an admin's uploads in
Settings → Languages are used first, so a plugin only needs the texts it adds:

```json
{
    "Sends new orders and payments to your phone through ntfy.": "Στέλνει τις νέες παραγγελίες και πληρωμές στο κινητό σου μέσω ntfy.",
    "New orders and payments as notifications on your phone.": "Νέες παραγγελίες και πληρωμές ως ειδοποιήσεις στο κινητό σου.",
    "A test from GaiaCP": "Δοκιμή από το GaiaCP",
    "Sent": "Στάλθηκε",
    "Not sent: check the URL and token, then save": "Δεν στάλθηκε: έλεγξε το URL και το token και αποθήκευσε",
    "Subscribe to the same topic in the ntfy app on your phone.": "Κάνε εγγραφή στο ίδιο topic από την εφαρμογή ntfy στο κινητό σου.",
    "Only for a protected topic. Stored encrypted; leave blank to keep the saved one.": "Μόνο για προστατευμένο topic. Αποθηκεύεται κρυπτογραφημένο· άφησέ το κενό για να μείνει το αποθηκευμένο.",
    "Topic URL": "URL του topic",
    "Access token": "Access token",
    "Send a test": "Αποστολή δοκιμής"
}
```

`php artisan gaiacp:translations el --plugin=Ntfy` lists what is still missing.

## 6. Try it

1. Open **Settings → Plugins**: ntfy is listed, found in `plugins/` by itself, and off. Switch it on (or open
   **Settings → ntfy** and use the switch on top).
2. On **Settings → ntfy**, enter `https://ntfy.sh/your-topic`, save, and **Send a test**: your phone rings.
3. Record a payment on any service (or place an order): a notification arrives with "Payment recorded: …".
4. **Settings → Logging → Module log** shows each call to ntfy, with its answer.

Switching the plugin off stops the notifications and keeps its settings.

A test for it, like GaiaCP's own in `tests/Feature/`, fakes ntfy with `Http::fake()` (tests may never reach a real
server) and checks what was sent:

```php
Http::fake(['ntfy.example.com/*' => Http::response('{}')]);
$plugins = app(PluginManager::class);
$plugins->enable($plugins->find('ntfy'));
config(['gaiacp.ntfy.url' => 'https://ntfy.example.com/gaia']);

app(RecordPayment::class)->handle(Service::factory()->create(), 6000, now(), 'cash');
app(DeferredCallbackCollection::class)->invoke(); // what defer() would run after the response

Http::assertSent(fn ($request) => str_contains($request->body(), 'Payment recorded'));
```

## 7. Package and share it

Zip the folder and upload it on another GaiaCP under **Settings → Plugins → Upload plugin** (super admins, with
their password):

```bash
cd plugins && zip -r Ntfy-1.0.0.zip Ntfy
```

GaiaCP checks the zip before installing it, puts it in `plugins/`, runs its migrations (it has none) and lists it
switched off. For a new version, raise `version` in `plugin.json`, add its section to `CHANGELOG.md` and upload the
new zip: only a newer version is accepted, and if its migrations fail the old one is put back.

## Where to go next

- A plugin with its own tables: `database/migrations/` in its folder (each with a `down()`), run on install and by
  every `php artisan migrate`.
- A scheduled job: `schedule(Schedule $schedule)` and `commands()`.
- A server panel, registrar, payment method, captcha, monitoring service or admin theme: implement the interface named
  in [writing-plugins.md](writing-plugins.md) and start from the bundled plugin of that kind.
- Something outside GaiaCP that only needs data: the [REST API](using-the-api.md) and webhooks may be enough, with no
  plugin at all.
