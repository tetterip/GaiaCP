<?php

namespace App\Filament\Client\Pages;

use App\Credit\ClientCredit;
use App\Filament\Client\Resources\Emails\EmailResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Invoicing\IssueInvoice;
use App\Plugins\PluginManager;
use App\Support\CookieConsent;
use App\Support\Locales;
use App\Support\PublicArea;
use App\Support\TermsOfService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * How to use the client area, from resources/docs/client-area/<language>.md (the default language's file when the
 * client's has none). `<!-- if:key -->` … `<!-- endif -->` keeps a part only while that feature is on, so the guide
 * never describes something the client can't do.
 */
class Help extends Page
{
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $title = 'Help';

    protected static ?string $slug = 'help';

    protected static ?int $navigationSort = 90;

    /** Support, after Tickets. */
    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected string $view = 'filament.client.help';

    public function guide(): HtmlString
    {
        $markdown = (string) file_get_contents(self::path(app()->getLocale()) ?? self::path(Locales::default()) ?? self::path('en'));

        // Innermost blocks first, so nested ones resolve.
        do {
            $markdown = preg_replace_callback(
                '/<!-- if:(\w+) -->\n((?:(?!<!-- if:)[\s\S])*?)<!-- endif -->\n?/',
                fn (array $m) => self::features()[$m[1]] ?? false ? $m[2] : '',
                $markdown,
                -1,
                $count,
            );
        } while ($count > 0);

        $email = config('gaiacp.company.email');
        $markdown = filled($email)
            ? str_replace('{company_email}', $email, $markdown)
            : preg_replace('/\n## [^\n]+\n\n[^\n]*\{company_email\}[^\n]*\n?/', "\n", $markdown);

        return new HtmlString(Str::markdown(preg_replace("/\n{3,}/", "\n\n", $markdown), ['html_input' => 'strip']));
    }

    /** @return array<string, bool> the guide's optional parts */
    public static function features(): array
    {
        $on = fn (string $key) => (bool) config("gaiacp.client_area.{$key}");
        $plugins = app(PluginManager::class);
        $panels = $plugins->serverModules()->isNotEmpty();
        $registrars = $plugins->registrarModules()->isNotEmpty();

        $features = [
            'hosting_login' => $panels && $on('hosting_login'),
            'hosting_password' => $panels && $on('hosting_password'),
            'hosting_usage' => $panels && $on('hosting_usage'),
            'hosting_uptime' => $panels && $on('hosting_uptime') && $plugins->monitoringModules()->isNotEmpty(),
            'vps_power' => $plugins->enabled('proxmox') && (bool) config('gaiacp.proxmox.client_power'),
            'incident_notice' => $panels && $on('incident_notice') && $plugins->monitoringModules()->isNotEmpty(),
            'domain_nameservers' => $registrars && $on('domain_nameservers'),
            'domain_epp' => $registrars && $on('domain_epp'),
            'domain_auto_renew' => $registrars && $on('domain_auto_renew'),
            'domain_contacts' => $registrars && $on('domain_contacts'),
            'domain_child_nameservers' => $registrars && $on('domain_child_nameservers'),
            'domain_lock' => $registrars && $on('domain_lock'),
            'ssl' => $plugins->certificateModules()->isNotEmpty(),
            'orders' => Order::canAccess(),
            'domain_prices' => DomainPrices::canAccess(),
            'pay_online' => $plugins->clientPaymentNoticeActions() !== [],
            'invoices' => IssueInvoice::enabled(),
            'credit' => ClientCredit::enabled(),
            'credit_auto' => ClientCredit::autoApply(),
            'credit_manual' => ClientCredit::enabled() && ! ClientCredit::autoApply(),
            'terms' => TermsOfService::required(),
            'tickets' => (bool) config('gaiacp.support.client_tickets'),
            'notifications' => $on('notifications'),
            'welcome_tour' => $on('welcome_tour'),
            'email_history' => EmailResource::canAccess(),
            'knowledge_base' => KnowledgeBase::canAccess(),
            'announcements' => Announcements::canAccess(),
            'server_status' => ServerStatus::canAccess(),
            'contact_page' => PublicArea::contactDetails(),
            'ticket_mail' => $plugins->enabled('ticket-mail') && (filled(config('gaiacp.ticket_mail.host')) || filled(config('gaiacp.ticket_mail.inbound_token'))),
            'cookies' => CookieConsent::enabled(),
            'google_login' => $plugins->enabled('google_login') && $plugins->find('google_login')->clientLoginButtons() !== [],
        ];
        $features['hosting'] = $features['hosting_login'] || $features['hosting_password'] || $features['hosting_usage'] || $features['hosting_uptime'] || $features['vps_power'];
        $features['domains'] = $features['domain_nameservers'] || $features['domain_epp'] || $features['domain_auto_renew']
            || $features['domain_contacts'] || $features['domain_child_nameservers'] || $features['domain_lock'];

        return $features;
    }

    private static function path(string $locale): ?string
    {
        $path = resource_path("docs/client-area/{$locale}.md");

        return is_file($path) ? $path : null;
    }
}
