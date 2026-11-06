<?php

namespace App\Plugins;

use App\Actions\CertificateLifecycle;
use App\Actions\ClientAreaAccess;
use App\Actions\ClientSignup;
use App\Actions\RecordInvoicePayment;
use App\Actions\Tickets;
use App\Ai\AiException;
use App\Ai\AiModule;
use App\Ai\Answer;
use App\Ai\ChatCompletionsPlugin;
use App\Ai\ChatCompletionsSettings;
use App\Ai\Prompt;
use App\Captcha\CaptchaModule;
use App\Certificates\CertificateModule;
use App\Certificates\CertificateOrder;
use App\Certificates\CertificateProduct;
use App\Certificates\CertificateState;
use App\Chat\ChatLine;
use App\Chat\ChatPlugin;
use App\Chat\ChatSender;
use App\Chat\ChatSettingsPage;
use App\Enums\AdminArea;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\CertificateStatus;
use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Enums\ClientStatus;
use App\Enums\CurrencyFormat;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\GaiaEvent;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Clusters\Settings\Pages\SupportSettings;
use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientField;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Monitoring\Monitor;
use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Monitoring\MonitorStatus;
use App\Monitoring\ServerMonitoring;
use App\Monitoring\UptimeHistory;
use App\Payments\PaymentModule;
use App\Providers\AppServiceProvider;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\ControlsPower;
use App\Provisioning\DescribesMachine;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\EmailsEppCode;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\InstallsCertificates;
use App\Provisioning\LimitsUsernameLength;
use App\Provisioning\ListsAccounts;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\MachineDetails;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ManualRegistrar;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarModule;
use App\Provisioning\ServerModule;
use App\Provisioning\TldPrice;
use App\SignIn\SignIn;
use App\Support\ClientFields;
use App\Support\Currencies;
use App\Support\Demo;
use App\Support\DomainName;
use App\Support\EppPhone;
use App\Support\Locales;
use App\Support\ModuleLogger;
use App\Support\Money;
use App\Support\Region;
use App\Support\Settings;
use App\Support\Tax;
use App\Themes\ThemeModule;

/**
 * What a plugin may use of GaiaCP (docs/plugin-api.md). STABLE is the public API: a release changes it only as that
 * page promises (named under "Plugin API" in CHANGELOG.md, the old way kept working for one more minor release where it
 * can). INTERNAL is what GaiaCP's own plugins also reach into: they ship with GaiaCP and change with it, so a plugin of
 * your own should not use these. PluginApiTest keeps the bundled plugins inside the two lists and the page in step.
 */
final class PluginApi
{
    /** @var list<class-string> */
    public const STABLE = [
        // Plugin basics
        Plugin::class,
        PluginManager::class,
        GaiaEvent::class,
        ActivityLog::class,
        ModuleLogger::class,

        // Server panels
        ServerModule::class,
        HostingProvisioner::class,
        AccountState::class,
        AccountUsage::class,
        PanelAccount::class,
        ProvisioningException::class,
        OffersShortcuts::class,
        ListsAccounts::class,
        ControlsPower::class,
        DescribesMachine::class,
        MachineDetails::class,
        InstallsCertificates::class,
        LimitsUsernameLength::class,

        // Domain registrars
        RegistrarModule::class,
        DomainRegistrar::class,
        ManualRegistrar::class,
        DomainContact::class,
        TldPrice::class,
        ListsTldPrices::class,
        ChecksAvailability::class,
        EmailsEppCode::class,
        ManagesDomainContacts::class,
        ManagesChildNameservers::class,
        ManagesRegistrarLock::class,
        DomainName::class,
        EppPhone::class,

        // Payments, captcha, monitoring, SSL certificates, chat, AI, themes
        PaymentModule::class,
        RecordInvoicePayment::class,
        PaymentNoticeResource::class,
        CaptchaModule::class,
        MonitoringModule::class,
        ServerMonitoring::class,
        Monitor::class,
        MonitorStatus::class,
        MonitoringException::class,
        CertificateModule::class,
        CertificateOrder::class,
        CertificateProduct::class,
        CertificateState::class,
        ChatPlugin::class,
        ChatLine::class,
        ChatSender::class,
        ChatSettingsPage::class,
        AiModule::class,
        Prompt::class,
        Answer::class,
        AiException::class,
        ChatCompletionsPlugin::class,
        ChatCompletionsSettings::class,
        ThemeModule::class,

        // Settings pages and the admin panel
        SettingsCluster::class,
        SettingsFormPage::class,
        PaymentMethodPage::class,
        PluginSwitch::class,
        SettingsCard::class,
        LimitedToAdminArea::class,
        TranslatesPageLabels::class,
        AdminArea::class,
        AppServiceProvider::class, // only dateFormat()

        // Records and values
        Client::class,
        Service::class,
        Server::class,
        Registrar::class,
        Product::class,
        Order::class,
        Invoice::class,
        InvoiceItem::class,
        Payment::class,
        User::class,
        ServiceType::class,
        ServiceStatus::class,
        BillingCycle::class,
        AutoSetup::class,
        ClientStatus::class,
        InvoiceStatus::class,
        DomainRegistrationType::class,
        UserRole::class,
        Money::class,
        Currencies::class,
        Locales::class,
        Region::class,
    ];

    /** @var list<class-string> used by bundled plugins only; may change in any release */
    public const INTERNAL = [
        CertificateLifecycle::class,
        ClientAreaAccess::class,
        ClientSignup::class,
        Tickets::class,
        CertificateStatus::class,
        ClientFieldAccess::class,
        ClientFieldType::class,
        CurrencyFormat::class,
        TicketStatus::class,
        AiSettings::class,
        SupportSettings::class,
        ClientResource::class,
        ServiceResource::class,
        ClientField::class,
        Currency::class,
        ProductGroup::class,
        Ticket::class,
        TicketDepartment::class,
        TicketMessage::class,
        UptimeHistory::class,
        SignIn::class,
        ClientFields::class,
        Demo::class,
        Settings::class,
        Tax::class,
    ];
}
