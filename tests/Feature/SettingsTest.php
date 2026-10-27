<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Clusters\Settings\Pages\AutomationSettings;
use App\Filament\Clusters\Settings\Pages\BackupSettings;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Filament\Clusters\Settings\Pages\CreditSettings;
use App\Filament\Clusters\Settings\Pages\DomainSettings;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Clusters\Settings\Pages\LanguageSettings;
use App\Filament\Clusters\Settings\Pages\LoggingSettings;
use App\Filament\Clusters\Settings\Pages\LoginSecuritySettings;
use App\Filament\Clusters\Settings\Pages\MailSettings;
use App\Filament\Clusters\Settings\Pages\PaymentMethodsSettings;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Filament\Clusters\Settings\Pages\SalesSettings;
use App\Filament\Clusters\Settings\Pages\SupportSettings;
use App\Filament\Clusters\Settings\Pages\TaxSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Pages\EmailTemplates;
use App\Filament\Pages\ErrorLog;
use App\Filament\Pages\FailedJobs;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\CannedReplies\CannedReplyResource;
use App\Filament\Resources\ClientFields\ClientFieldResource;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Filament\Resources\IpBans\IpBanResource;
use App\Filament\Resources\ModuleLogs\ModuleLogResource;
use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Registrars\RegistrarResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use App\Mail\RenewalReminderMail;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\Aade\AadeSettings;
use Plugins\BankTransfer\BankTransferSettings;
use Plugins\BetterStack\BetterStackSettings;
use Plugins\Blacklists\BlacklistsSettings;
use Plugins\Claude\ClaudeSettings;
use Plugins\CyberPanel\CyberPanelSettings;
use Plugins\DeepSeek\DeepSeekSettings;
use Plugins\Discord\DiscordSettings;
use Plugins\GoGetSsl\GoGetSslSettings;
use Plugins\GoogleLogin\GoogleLoginSettings;
use Plugins\HetrixTools\HetrixToolsSettings;
use Plugins\Kimi\KimiSettings;
use Plugins\Ollama\OllamaSettings;
use Plugins\OpenAi\OpenAiSettings;
use Plugins\Proxmox\ProxmoxSettings;
use Plugins\Recaptcha\RecaptchaSettings;
use Plugins\SiteChecks\SiteChecksSettings;
use Plugins\Slack\SlackSettings;
use Plugins\Stripe\StripeSettings;
use Plugins\Telegram\TelegramSettings;
use Plugins\TicketMail\TicketMailSettings;
use Plugins\Turnstile\TurnstileSettings;
use Plugins\UptimeKuma\UptimeKumaSettings;
use Plugins\UptimeRobot\UptimeRobotSettings;
use Plugins\Vies\ViesSettings;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Every card on the Settings page, in order. */
    private const PAGES = [
        GeneralSettings::class, LanguageSettings::class, CompanySettings::class, PaymentMethodsSettings::class, TaxSettings::class, CreditSettings::class, SalesSettings::class, AadeSettings::class, ViesSettings::class, ClientFieldResource::class, GoogleLoginSettings::class, RecaptchaSettings::class, TurnstileSettings::class, UptimeKumaSettings::class, UptimeRobotSettings::class, HetrixToolsSettings::class, BetterStackSettings::class, GoGetSslSettings::class, BlacklistsSettings::class, SiteChecksSettings::class, ProxmoxSettings::class, AiSettings::class, ClaudeSettings::class, DeepSeekSettings::class, KimiSettings::class, OpenAiSettings::class, OllamaSettings::class,
        ProductResource::class, ProductOptionGroupResource::class, ServerResource::class, RegistrarResource::class, DomainSettings::class, ClientAreaSettings::class, PublicAreaSettings::class, CookieSettings::class,
        SupportSettings::class, TicketDepartmentResource::class, CannedReplyResource::class, TicketMailSettings::class,
        MailSettings::class, EmailTemplates::class,
        AutomationSettings::class, BackupSettings::class, SystemHealth::class,
        LoggingSettings::class, ModuleLogResource::class, EmailLogResource::class, ErrorLog::class, FailedJobs::class, SlackSettings::class, DiscordSettings::class, TelegramSettings::class,
        LoginSecuritySettings::class, IpBanResource::class,
        CyberPanelSettings::class,
    ];

    /** Listed on Settings → Payment methods and under it in the menu, without a card of their own. */
    private const PAYMENT_PAGES = [BankTransferSettings::class, StripeSettings::class];

    public function test_settings_shows_a_card_per_page_and_each_page_has_the_sub_navigation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee(SettingsCluster::getUrl());

        $overview = $this->get(SettingsCluster::getUrl())->assertOk();
        foreach (self::PAGES as $page) {
            $overview->assertSee($page::getUrl())->assertSee($page::settingsDescription());
        }
        foreach (self::PAYMENT_PAGES as $page) {
            $overview->assertDontSee($page::settingsDescription());
        }
        $overview->assertSeeInOrder(array_map('e', SettingsCluster::GROUPS), false)->assertSee(__('Search settings'));

        // Every page shows the others on its left, grouped.
        $this->get(TaxSettings::getUrl())->assertOk()
            ->assertSee(MailSettings::getUrl())
            ->assertSee(ServerResource::getUrl())
            ->assertSeeInOrder(['General', 'General', 'Languages', 'Company', 'Client fields', 'Billing', 'Payment methods', 'Currencies', 'Tax', 'Client credit', 'Automation', 'Sales', 'Products &amp; domains', 'Products', 'Product options', 'Domain pricing', 'Domains', 'Registrars', 'Servers', 'Client area', 'Client area', 'Public area', 'Cookies', 'Support', 'Ticket departments', 'Canned replies', 'Email to ticket', 'Email &amp; notifications', 'Mail', 'Email templates', 'Integrations', 'ΑΑΔΕ', 'AI assistant', 'AI assistant', 'Security', 'Admin users', 'Login security', 'System', 'Plugins', 'Updates', 'Backups', 'System health', 'Logs', 'Logging', 'Module log', 'Email log', 'Error log'], false);
    }

    public function test_every_stored_setting_is_on_exactly_one_page(): void
    {
        $keys = collect([...self::PAGES, ...self::PAYMENT_PAGES])
            ->filter(fn (string $page) => is_subclass_of($page, SettingsFormPage::class))
            ->flatMap(fn (string $page) => $page::keys());

        $this->assertSame($keys->count(), $keys->unique()->count());
        $this->assertEqualsCanonicalizing(Settings::keys(), $keys->all());
    }

    public function test_settings_pages_live_under_settings_not_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create());

        $sidebar = collect(Filament::getNavigation())->flatMap->getItems()->map->getLabel()->all();
        $this->assertNotContains('Servers', $sidebar);
        $this->assertNotContains('Products', $sidebar);
        $this->assertNotContains('Tax', $sidebar);
        $this->assertContains('Domains', $sidebar);
        $this->assertContains('Settings', $sidebar);

        $this->assertStringEndsWith('/admin/settings/servers', ServerResource::getUrl());
        $this->get('/admin/servers')->assertRedirect('/admin/settings/servers');
        $this->get('/admin/products/3/edit')->assertRedirect('/admin/settings/products/3/edit');
    }

    public function test_saved_settings_override_config_now_and_on_next_boot(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['name' => 'ACME', 'email' => 'billing@acme.gr', 'phone' => '2100000000', 'website' => null, 'address' => null, 'vat_number' => '123456789', 'tax_office' => 'Α΄ Αθηνών']])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(AutomationSettings::class)
            ->fillForm(['reminders' => ['send_at' => '08:30', 'before_days' => ['7', '30'], 'overdue_days' => ['3'], 'bcc' => ''], 'automation' => ['suspend_after_days' => '10']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([30, 7], config('gaiacp.reminders.before_days'));
        $this->assertSame([3], config('gaiacp.reminders.overdue_days'));
        $this->assertSame(10, config('gaiacp.automation.suspend_after_days'));
        $this->assertNull(config('gaiacp.reminders.bcc'));
        $this->assertSame('billing@acme.gr', config('mail.from.address'));
        $this->assertSame('ACME', config('mail.from.name'));

        // A fresh request (or the daily cron) starts from .env defaults and loads the stored values.
        config(['gaiacp.reminders.send_at' => '09:00', 'gaiacp.automation.suspend_after_days' => null]);
        Settings::apply();

        $this->assertSame('08:30', config('gaiacp.reminders.send_at'));
        $this->assertSame(10, config('gaiacp.automation.suspend_after_days'));
    }

    public function test_saving_a_page_stores_only_its_own_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GeneralSettings::class)
            ->fillForm(['invoices' => ['days_before' => '10']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['region.timezone', 'region.date_format', 'region.country', 'invoices.days_before', 'theme.default', 'captcha.provider', 'captcha.forms'], Setting::query()->pluck('key')->all());
        $this->assertSame(10, config('gaiacp.invoices.days_before'));
    }

    public function test_emptying_suspend_after_days_turns_auto_suspend_off(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.automation.suspend_after_days' => 5]);

        Livewire::test(AutomationSettings::class)
            ->fillForm(['automation' => ['suspend_after_days' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Setting::query()->whereKey('automation.suspend_after_days')->exists());
        config(['gaiacp.automation.suspend_after_days' => 5]);
        Settings::apply();
        $this->assertNull(config('gaiacp.automation.suspend_after_days'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['email' => 'not-an-email']])
            ->call('save')
            ->assertHasFormErrors(['company.email']);

        Livewire::test(AutomationSettings::class)
            ->fillForm(['reminders' => ['send_at' => '25:00', 'before_days' => ['abc']], 'automation' => ['suspend_after_days' => '0']])
            ->call('save')
            ->assertHasFormErrors(['reminders.send_at', 'automation.suspend_after_days']);

        $this->assertSame(0, Setting::count());
    }

    public function test_default_nameservers_are_saved_lowercase_from_the_domains_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['nameservers' => ['NS1.ACME.GR ', 'ns2.acme.gr', 'ns1.acme.gr']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['ns1.acme.gr', 'ns2.acme.gr'], config('gaiacp.domains.nameservers'));
    }

    public function test_reminder_email_is_signed_with_the_company_details(): void
    {
        Settings::save(['company.name' => 'Acme Hosting', 'company.phone' => '2100000000']);
        $service = Service::factory()->create();

        $html = (new RenewalReminderMail($service->client, [['service' => $service, 'kind' => 'before:7']], CarbonImmutable::today()))->render();

        $this->assertStringContainsString('Acme Hosting', $html);
        $this->assertStringContainsString('2100000000', $html);
    }

    public function test_a_settings_page_names_its_group_and_keeps_save_in_view(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(CompanySettings::getUrl())->assertOk()
            ->assertSee(CompanySettings::settingsDescription())
            ->assertSee('gx-save-bar', false)
            ->assertSee('You have unsaved changes');

        $this->assertSame(
            [SettingsCluster::getUrl() => 'Settings', 'General'],
            Livewire::test(CompanySettings::class)->instance()->getBreadcrumbs(),
        );
        $this->assertSame(
            [SettingsCluster::getUrl() => 'Settings', PaymentMethodsSettings::getUrl() => 'Payment methods'],
            Livewire::test(StripeSettings::class)->instance()->getBreadcrumbs(),
        );

        // Cancel puts the stored values back in the form
        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['name' => 'Changed']])
            ->call('discard')
            ->assertSet('data.company.name', config('gaiacp.company.name'))
            ->assertDispatched('settings-clean');
        $this->assertNotSame('Changed', config('gaiacp.company.name'));
    }
}
