<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\EditEmailTemplate;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Mail\ClientMail;
use App\Mail\ClientMailer;
use App\Mail\RenewalReminderMail;
use App\Models\Client;
use App\Models\EmailTemplateTranslation;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Locales;
use App\Support\MailTemplates;
use App\Support\TranslationKeys;
use App\Support\Translations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PhpToken;
use Tests\TestCase;

class LocalesTest extends TestCase
{
    use RefreshDatabase;

    /** A second language for the test, with a few lines of a made-up lang/el.json. */
    private function withGreek(array $lines = []): void
    {
        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά']]);

        app('translator')->addLines(collect($lines)->mapWithKeys(fn ($v, $k) => ["*.{$k}" => $v])->all(), 'el');
    }

    public function test_with_one_language_nothing_changes(): void
    {
        config(['gaiacp.locales' => ['en' => 'English']]);

        $this->assertFalse(Locales::multiple());
        $this->assertSame(['en' => 'English'], Locales::available());
        $this->assertSame('en', Locales::normalize('el'));

        $this->actingAs(User::factory()->create());
        $this->get(EditProfile::getUrl())->assertOk()->assertDontSee('Panel language');
        $this->get(EditClient::getUrl(['record' => Client::factory()->create()]))->assertOk()->assertDontSee('Email language');
    }

    public function test_the_panel_uses_the_admins_language(): void
    {
        $this->withGreek(['Clients' => 'Πελάτες', 'First name' => 'Όνομα', 'Contact' => 'Επικοινωνία', 'Settings' => 'Ρυθμίσεις']);
        $client = Client::factory()->create();

        $this->actingAs($admin = User::factory()->create());
        $this->get(EditClient::getUrl(['record' => $client]))->assertOk()->assertSee('Clients')->assertDontSee('Πελάτες');

        Livewire::test(EditProfile::class)
            ->assertSee('Panel language')
            ->set('data.locale', 'el')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('el', $admin->fresh()->locale);

        $this->actingAs($admin->fresh());
        $this->get(EditClient::getUrl(['record' => $client]))->assertOk()
            ->assertSee('Πελάτες')    // menu (resource label)
            ->assertSee('Όνομα')      // label Filament made from first_name
            ->assertSee('Επικοινωνία') // section heading
            ->assertSee('Ρυθμίσεις')  // user menu item
            ->assertSee('Αποθήκευση'); // Filament's own Greek ("Save changes")
    }

    public function test_an_unknown_admin_language_falls_back_to_the_default(): void
    {
        $this->withGreek(['Clients' => 'Πελάτες']);
        $this->actingAs(User::factory()->create(['locale' => 'xx']));

        $this->get(EditClient::getUrl(['record' => Client::factory()->create()]))->assertOk()->assertDontSee('Πελάτες');
    }

    public function test_emails_go_out_in_the_clients_language_with_the_default_text_as_fallback(): void
    {
        $this->withGreek(['Service' => 'Υπηρεσία', 'Due date' => 'Λήξη']);
        Mail::fake();

        MailTemplates::save(MailTemplate::ClientSignup, 'Καλώς ήρθατε στην {$company_name}', 'Γεια σας {$client_first_name}', locale: 'el');

        $greek = Client::factory()->create(['first_name' => 'Νίκος', 'locale' => 'el']);
        $english = Client::factory()->create(['first_name' => 'Nick']);

        app(ClientMailer::class)->send(MailTemplate::ClientSignup, $greek, evenIfOff: true);
        app(ClientMailer::class)->send(MailTemplate::ClientSignup, $english, evenIfOff: true);

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->hasTo($greek->email)
            && str_starts_with($mail->envelope()->subject, 'Καλώς ήρθατε')
            && str_contains($mail->render(), 'Γεια σας Νίκος'));
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->hasTo($english->email)
            && $mail->envelope()->subject === 'Welcome to '.config('gaiacp.company.name'));

        // No Greek text saved for this one: the built-in Greek text, with the tables in Greek.
        $service = Service::factory()->dueOn(now()->addDays(7)->toDateString())->create(['client_id' => $greek]);
        $reminder = new RenewalReminderMail($greek, [['service' => $service, 'kind' => 'before:7']], now()->toImmutable());

        $this->assertSame('Επερχόμενη ανανέωση των υπηρεσιών σας', $reminder->envelope()->subject);
        $this->assertStringContainsString('Υπηρεσία', $reminder->render());
        $this->assertStringContainsString('Αγαπητέ/ή Νίκος', $reminder->render());
        $this->assertSame('en', app()->getLocale());
    }

    public function test_every_email_has_built_in_greek_text_with_the_same_placeholders(): void
    {
        $placeholders = function (string $text): array {
            preg_match_all('/\{\$([a-z_]+)\}/', $text, $m);

            return collect($m[1])->unique()->sort()->values()->all();
        };

        $this->assertTrue(MailTemplate::hasBuiltIn('el'));
        $this->assertFalse(MailTemplate::hasBuiltIn('da'));

        foreach (MailTemplate::cases() as $t) {
            $subject = $t->defaultSubject('el');
            $body = $t->defaultBody('el');

            $this->assertNotSame($t->defaultSubject(), $subject, $t->value);
            $this->assertNotSame($t->defaultBody(), $body, $t->value);
            $this->assertSame($placeholders($t->defaultSubject()), $placeholders($subject), $t->value);
            $this->assertSame($placeholders($t->defaultBody()), $placeholders($body), $t->value);
            $this->assertStringStartsWith('Αγαπητέ/ή {$client_first_name},', $body);
            // Payment notices are proformas, never invoices; only the email of a real invoice says so.
            if ($t !== MailTemplate::InvoiceIssued) {
                $this->assertDoesNotMatchRegularExpression('/τιμολ[οό]γ/iu', $subject.$body, $t->value);
            }
        }

        // The machine translated languages have every email too, with the same placeholders
        foreach (Translations::MACHINE_TRANSLATED as $locale) {
            $this->assertTrue(MailTemplate::hasBuiltIn($locale), $locale);
            foreach (MailTemplate::cases() as $t) {
                $this->assertNotSame($t->defaultBody(), $t->defaultBody($locale), "{$locale} {$t->value}");
                $this->assertSame($placeholders($t->defaultSubject()), $placeholders($t->defaultSubject($locale)), "{$locale} {$t->value}");
                $this->assertSame($placeholders($t->defaultBody()), $placeholders($t->defaultBody($locale)), "{$locale} {$t->value}");
            }
        }

        // The preview's sample tables are in the language previewed.
        $this->withGreek(['Description' => 'Περιγραφή']);
        $preview = ClientMail::sample(MailTemplate::InvoiceCreated, 'el')->locale('el');
        $this->assertSame('Ειδοποίηση πληρωμής PF-2026-0042', $preview->envelope()->subject);
        $this->assertStringContainsString('Περιγραφή', $preview->render());
    }

    public function test_a_language_without_built_in_text_gets_the_default_languages_text(): void
    {
        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά', 'da' => 'Dansk']]);
        MailTemplates::save(MailTemplate::ClientSignup, 'Welcome aboard', null);

        $this->assertSame('Welcome aboard', MailTemplates::get(MailTemplate::ClientSignup, 'da')['subject']);
        $this->assertSame(MailTemplate::ClientSignup->defaultBody(), MailTemplates::get(MailTemplate::ClientSignup, 'da')['body']);
        // Greek has its own built-in text, which an edit of the English text doesn't replace.
        $this->assertSame(MailTemplate::ClientSignup->defaultSubject('el'), MailTemplates::get(MailTemplate::ClientSignup, 'el')['subject']);
    }

    public function test_saving_another_language_stores_only_what_differs_and_shares_the_switch(): void
    {
        $this->withGreek();
        $t = MailTemplate::HostingWelcome;

        MailTemplates::save($t, $t->defaultSubject('el'), $t->defaultBody('el'), true, 'el');
        $this->assertSame(0, EmailTemplateTranslation::count());
        $this->assertTrue(MailTemplates::enabled($t));

        MailTemplates::save($t, 'Ο λογαριασμός σας', $t->defaultBody('el'), true, 'el');
        $this->assertSame(['subject' => 'Ο λογαριασμός σας', 'body' => null], EmailTemplateTranslation::sole()->only('subject', 'body'));
        $this->assertSame('Ο λογαριασμός σας', MailTemplates::get($t, 'el')['subject']);
        $this->assertSame($t->defaultBody('el'), MailTemplates::get($t, 'el')['body']);
        $this->assertSame($t->defaultSubject(), MailTemplates::get($t, 'en')['subject']);

        MailTemplates::save($t, '', '', true, 'el');
        $this->assertSame(0, EmailTemplateTranslation::count());
    }

    public function test_the_template_page_edits_each_language(): void
    {
        $this->withGreek();
        $this->actingAs(User::factory()->create());
        MailTemplates::save(MailTemplate::HostingWelcome, 'Ο λογαριασμός σας', null, locale: 'el');

        Livewire::test(EditEmailTemplate::class, ['template' => 'hosting_welcome'])
            ->assertSet('data.locale', 'en')
            ->assertSet('data.subject', MailTemplate::HostingWelcome->defaultSubject())
            ->set('data.locale', 'el')
            ->assertSet('data.subject', 'Ο λογαριασμός σας')
            ->set('data.body', 'Καλώς ήρθατε {$client_first_name}')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Καλώς ήρθατε {$client_first_name}', MailTemplates::get(MailTemplate::HostingWelcome, 'el')['body']);
        $this->assertSame(MailTemplate::HostingWelcome->defaultBody(), MailTemplates::get(MailTemplate::HostingWelcome, 'en')['body']);

        // Reset in Greek puts back the built-in Greek text.
        Livewire::test(EditEmailTemplate::class, ['template' => 'hosting_welcome'])
            ->set('data.locale', 'el')
            ->callAction(TestAction::make('reset')->schemaComponent('templateActions', 'form'))
            ->assertSet('data.subject', MailTemplate::HostingWelcome->defaultSubject('el'))
            ->assertSet('data.body', MailTemplate::HostingWelcome->defaultBody('el'));
    }

    public function test_the_translations_command_lists_the_texts_to_translate(): void
    {
        $keys = TranslationKeys::all();

        foreach (['Payment notices', 'First name', ':count payment(s) recorded', 'Hosting Account Welcome Email', 'Bank transfer', 'Keyboard shortcuts', 'Go to'] as $key) {
            $this->assertContains($key, $keys);
        }

        // English keys whatever the admin's and the default language (the shortcuts list is translated when built)
        config(['gaiacp.locales' => ['el' => 'Ελληνικά', 'en' => 'English', 'de' => 'Deutsch']]);
        app()->setLocale('de');
        $keys = TranslationKeys::all();
        app()->setLocale('en');
        $this->assertContains('Show these shortcuts', $keys);
        $this->assertNotContains('Diese Tastenkürzel anzeigen', $keys);
        $this->assertNotContains('Εμφάνιση αυτών των συντομεύσεων', $keys);
        config(['gaiacp.locales' => null]);

        $this->artisan('gaiacp:translations', ['locale' => 'da'])
            ->expectsOutputToContain('Payment notices')
            ->assertSuccessful();
    }

    /** Every text has its Greek version: add new ones with `php artisan gaiacp:translations el --write`. */
    public function test_every_text_is_translated_into_greek(): void
    {
        $read = fn (string $path) => is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $greek = $read(lang_path('el.json'));

        $this->assertSame([], array_values(array_diff(TranslationKeys::core(), array_keys($greek))), 'Missing from lang/el.json');
        $this->assertSame([], array_keys(array_filter($greek, fn (string $text) => $text === '')), 'Empty in lang/el.json');

        // A plugin's texts are in its own lang/el.json, or in the app's.
        foreach (app(PluginManager::class)->all() as $plugin) {
            $own = $read($plugin->path().'/lang/el.json');
            $name = basename($plugin->path());

            $this->assertSame([], array_values(array_diff(TranslationKeys::forPlugin($plugin), array_keys($greek), array_keys($own))), "Missing from plugins/{$name}/lang/el.json");
            $this->assertSame([], array_keys(array_filter($own, fn (string $text) => $text === '')), "Empty in plugins/{$name}/lang/el.json");
        }
    }

    /**
     * Filament's (and any other package's) texts have Greek too: what its own Greek files lack goes in
     * lang/vendor/<namespace>/el/<file>.php, which Laravel merges over them. So do Laravel's auth, passwords and
     * pagination texts (validation covers only the rules GaiaCP uses, see lang/el/validation.php).
     */
    public function test_every_package_text_is_translated_into_greek(): void
    {
        $flatten = fn (array $lines): array => array_keys(array_filter(Arr::dot($lines), fn ($line) => is_string($line)));
        $missing = [];

        foreach (app('translator')->getLoader()->namespaces() as $namespace => $path) {
            foreach (File::allFiles($path.'/en') as $file) {
                $group = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -4));

                foreach ($flatten(require $file->getPathname()) as $key) {
                    if (! Lang::has("{$namespace}::{$group}.{$key}", 'el', false)) {
                        $missing[] = "lang/vendor/{$namespace}/el/{$group}.php: {$key}";
                    }
                }
            }
        }

        foreach (['auth', 'pagination', 'passwords'] as $group) {
            foreach ($flatten(require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php")) as $key) {
                if (! Lang::has("{$group}.{$key}", 'el', false)) {
                    $missing[] = "lang/el/{$group}.php: {$key}";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Text in the panel goes through __() (labels are translated by Filament itself, see
     * AppServiceProvider). A sentence built from pieces can't be translated: use :placeholders.
     */
    public function test_panel_text_is_translatable(): void
    {
        $skipCalls = ['__', 'label', 'make', 'record', 'error', 'info', 'warning', 'icon', 'color', 'config', 'view', 'url'];
        $untranslated = [];

        // The panel's own classes, then the plugins' panel code (the files that use Filament).
        $files = collect(File::allFiles(app_path('Filament')))->map(fn ($file) => [$file, 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname())])
            ->merge(collect(File::allFiles(base_path('plugins')))->filter(fn ($file) => $file->getExtension() === 'php' && str_contains($file->getContents(), 'use Filament\\'))
                ->map(fn ($file) => [$file, 'Plugins\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname())]));

        foreach ($files as [$file, $class]) {
            $tokens = PhpToken::tokenize($file->getContents());
            $lines = explode("\n", $file->getContents());
            $calls = [];

            foreach ($tokens as $i => $token) {
                $previous = $this->significant($tokens, $i, -1);

                if (in_array($token->text, ['(', '['], true)) {
                    $calls[] = $token->text === '(' && $previous !== null && $tokens[$previous]->is(T_STRING) ? $tokens[$previous]->text : '';

                    continue;
                }
                if (in_array($token->text, [')', ']'], true)) {
                    array_pop($calls);

                    continue;
                }

                $text = $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : ($token->is(T_ENCAPSED_AND_WHITESPACE) ? $token->text : null);
                if ($text === null || ! preg_match('/^\s*[A-ZΑ-Ω(].*\s.*\p{Ll}{2}/u', $text) || preg_match('/<|=|\\$\\{|::/', $text)) {
                    continue;
                }

                $next = $this->significant($tokens, $i, 1);
                $inSkippedCall = array_intersect($calls, $skipCalls) !== [];
                $isKey = $tokens[$next]->text === '=>';
                $line = $lines[$token->line - 1];
                $isConstant = str_contains($line, ' const '); // translated where shown
                $isStaticLabel = preg_match('/static .*\$(title|navigationLabel|modelLabel|pluralModelLabel|navigationGroup) =/', $line) === 1;

                if ($isStaticLabel) {
                    $this->assertNotEmpty(array_intersect(class_uses_recursive($class), [TranslatesPageLabels::class, TranslatesResourceLabels::class, TranslatesRelationManagerTitle::class]), "{$class} needs a Translates… trait for its {$text}");

                    continue;
                }

                if (! $inSkippedCall && ! $isKey && ! $isConstant && ! str_contains($text, '{$')) {
                    $untranslated[] = $class.":{$token->line} {$text}";
                }
            }
        }

        $this->assertSame([], $untranslated, 'Wrap these in __() (with :placeholders for values).');
    }

    /** An action without ->label() is labelled from its name ('suspend' → "Suspend"), which needs Greek too. */
    public function test_action_labels_made_from_their_names_are_translated(): void
    {
        $greek = json_decode(File::get(lang_path('el.json')), true);
        foreach (File::glob(base_path('plugins/*/lang/el.json')) as $file) {
            $greek += json_decode(File::get($file), true);
        }
        $missing = [];

        $files = collect(File::allFiles(app_path('Filament')))->merge(File::allFiles(base_path('plugins')))
            ->filter(fn ($file) => $file->getExtension() === 'php');

        foreach ($files as $file) {
            $code = $file->getContents();
            preg_match_all("/(?:Action|ActionGroup)::make\\('(\\w+)'\\)(.{0,400})/s", $code, $actions, PREG_SET_ORDER);
            preg_match_all("/->label\\(\\$\\w+ \\? '([^']+)' : '([^']+)'\\)/", $code, $choices, PREG_SET_ORDER);

            foreach ($actions as [, $name, $after]) {
                $chain = preg_split('/;|::make\\(/', $after)[0];
                if (preg_match('/->(label|tooltip|iconButton|hiddenLabel)\\(/', $chain)) {
                    continue;
                }
                $label = ucfirst(strtolower(trim(preg_replace('/([a-z])([A-Z])|_/', '$1 $2', $name))));
                if (! isset($greek[$label])) {
                    $missing[] = "{$file->getRelativePathname()}: {$label}";
                }
            }
            foreach ($choices as [, $first, $second]) {
                foreach ([$first, $second] as $label) {
                    if (! isset($greek[$label])) {
                        $missing[] = "{$file->getRelativePathname()}: {$label}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Add these labels to lang/el.json');
    }

    private function significant(array $tokens, int $from, int $step): ?int
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    public function test_guide_translations_have_every_paragraph_of_the_english_one(): void
    {
        // Client guide: one paragraph per line, so a translation has the same lines as en.md.
        $shape = fn (string $path) => array_map(fn ($line) => match (true) {
            $line === '' => '',
            str_starts_with($line, '<!--') => $line,
            str_starts_with($line, '#') => strtok($line, ' '),
            str_starts_with($line, '- ') => '-',
            default => 'text',
        }, file($path, FILE_IGNORE_NEW_LINES));

        $english = $shape(resource_path('docs/client-area/en.md'));

        foreach (File::glob(resource_path('docs/client-area/*.md')) as $path) {
            $this->assertSame($english, $shape($path), basename($path).' is missing a paragraph of en.md');
        }

        // Admin guide: wrapped lines differ, so compare each chapter's headings.
        $headings = fn (string $path) => preg_match_all('/^#+ /m', File::get($path));

        foreach (File::glob(base_path('docs/admin-guide/*/*.md')) as $path) {
            $english = base_path('docs/admin-guide/en/'.basename($path));
            $this->assertFileExists($english);
            $this->assertSame($headings($english), $headings($path), $path.' does not have the sections of the English chapter');
        }
    }

    public function test_validation_messages_are_greek_in_greek(): void
    {
        app()->setLocale('el');

        $errors = validator(['term' => '3', 'email' => ''], ['term' => 'in:1,2', 'email' => 'required'], [], ['term' => 'διάρκεια', 'email' => 'Email'])->errors();

        $this->assertSame('Η επιλογή στο πεδίο «διάρκεια» δεν είναι έγκυρη.', $errors->first('term'));
        $this->assertSame('Το πεδίο «Email» είναι υποχρεωτικό.', $errors->first('email'));
    }
}
