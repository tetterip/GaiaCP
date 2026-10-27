<?php

namespace App\Enums;

use App\Providers\AppServiceProvider;

/**
 * The emails GaiaCP sends to clients, named as in WHMCS. Each one has a built-in subject and
 * body (Markdown, HTML allowed) that the admin can override, and a switch for sending it
 * automatically, on the Email templates page; see App\Support\MailTemplates.
 */
enum MailTemplate: string
{
    case ClientSignup = 'client_signup';
    case OrderConfirmation = 'order_confirmation';
    case ClientAreaInvite = 'client_area_invite';
    case ClientAreaLogin = 'client_area_login';
    case EmailVerification = 'email_verification';

    case RenewalReminder = 'renewal_reminder';
    case OverdueNotice = 'overdue_notice';
    // Payment notices (proformas, not myDATA invoices); the invoices work owns sending them.
    case InvoiceCreated = 'invoice_created';
    case InvoicePaymentReminder = 'invoice_payment_reminder';
    case InvoiceFirstOverdue = 'invoice_overdue_first';
    case InvoiceSecondOverdue = 'invoice_overdue_second';
    case InvoiceModified = 'invoice_modified';
    case PriceChange = 'price_change';
    case Quote = 'quote';

    case HostingWelcome = 'hosting_welcome';
    case ServiceSuspension = 'service_suspension';
    case ServiceUnsuspension = 'service_unsuspension';
    case CancellationApproved = 'cancellation_approved';
    case CancellationRejected = 'cancellation_rejected';
    case UpgradeApproved = 'upgrade_approved';
    case UpgradeRejected = 'upgrade_rejected';
    case HostingOffer = 'hosting_offer';
    case UsageWarning = 'usage_warning';

    case DomainRegistration = 'domain_registration';
    case DomainRenewal = 'domain_renewal';
    case DomainTransferCompleted = 'domain_transfer_completed';
    case DomainRenewalNotice = 'domain_renewal_notice';
    case DomainExpired = 'domain_expired';
    case DomainTransferOffer = 'domain_transfer_offer';
    case DomainWinBack = 'domain_winback';

    case SslValidation = 'ssl_validation';
    case SslIssued = 'ssl_issued';

    case TicketOpened = 'ticket_opened';
    case TicketReply = 'ticket_reply';
    case TicketClosed = 'ticket_closed';

    /** Groups in the order the Email templates page lists them. */
    public const GROUPS = ['General', 'Billing', 'Hosting', 'Domains', 'SSL certificates', 'Support'];

    public function label(): string
    {
        return __(match ($this) {
            self::ClientSignup => 'Client Signup Email',
            self::OrderConfirmation => 'Order Confirmation',
            self::ClientAreaInvite => 'Client Area Invitation',
            self::ClientAreaLogin => 'Client Area Login Details',
            self::EmailVerification => 'Email Address Verification',
            self::RenewalReminder => 'Upcoming Renewal Notice',
            self::OverdueNotice => 'Overdue Notice',
            self::InvoiceCreated => 'Payment Notice Created',
            self::InvoicePaymentReminder => 'Payment Notice Reminder',
            self::InvoiceFirstOverdue => 'First Payment Notice Overdue',
            self::InvoiceSecondOverdue => 'Second Payment Notice Overdue',
            self::InvoiceModified => 'Payment Notice Modified',
            self::PriceChange => 'Price Change Notice',
            self::Quote => 'Quote',
            self::HostingWelcome => 'Hosting Account Welcome Email',
            self::ServiceSuspension => 'Service Suspension Notification',
            self::ServiceUnsuspension => 'Service Unsuspension Notification',
            self::CancellationApproved => 'Cancellation Request Approved',
            self::CancellationRejected => 'Cancellation Request Declined',
            self::UpgradeApproved => 'Upgrade Request Approved',
            self::UpgradeRejected => 'Upgrade Request Declined',
            self::HostingOffer => 'Hosting Offer',
            self::UsageWarning => 'Usage Warning',
            self::DomainRegistration => 'Domain Registration Confirmation',
            self::DomainRenewal => 'Domain Renewal Confirmation',
            self::DomainTransferCompleted => 'Domain Transfer Completed',
            self::DomainRenewalNotice => 'Upcoming Domain Renewal Notice',
            self::DomainExpired => 'Expired Domain Notice',
            self::DomainTransferOffer => 'Domain Transfer Offer',
            self::DomainWinBack => 'Expired Domain Win-back',
            self::SslValidation => 'SSL Certificate Validation',
            self::SslIssued => 'SSL Certificate Issued',
            self::TicketOpened => 'Support Ticket Opened',
            self::TicketReply => 'Support Ticket Reply',
            self::TicketClosed => 'Support Ticket Closed',
        });
    }

    public function group(): string
    {
        return __(match ($this) {
            self::ClientSignup, self::OrderConfirmation, self::ClientAreaInvite, self::ClientAreaLogin, self::EmailVerification => 'General',
            self::RenewalReminder, self::OverdueNotice, self::InvoiceCreated, self::InvoicePaymentReminder,
            self::InvoiceFirstOverdue, self::InvoiceSecondOverdue, self::InvoiceModified, self::PriceChange, self::Quote => 'Billing',
            self::HostingWelcome, self::ServiceSuspension, self::ServiceUnsuspension,
            self::CancellationApproved, self::CancellationRejected, self::UpgradeApproved, self::UpgradeRejected, self::HostingOffer, self::UsageWarning => 'Hosting',
            self::SslValidation, self::SslIssued => 'SSL certificates',
            self::TicketOpened, self::TicketReply, self::TicketClosed => 'Support',
            default => 'Domains',
        });
    }

    /** When GaiaCP sends it (if its switch is on). */
    public function sentWhen(): string
    {
        return __(match ($this) {
            self::ClientSignup => 'When you add a client with "Send the Client Signup Email" ticked.',
            self::OrderConfirmation => 'When an order is placed.',
            self::ClientAreaInvite => 'When you press "Invite to client area" on a client.',
            self::ClientAreaLogin => 'When you add a client with a client area password and "Email the login details" ticked.',
            self::EmailVerification => 'When someone signs up in the client area, and when they ask for the link again. Always sent: they can\'t log in without it.',
            self::RenewalReminder, self::OverdueNotice => 'No longer sent: payment notices took its place.',
            self::InvoiceCreated => 'When the daily run creates a payment notice (proforma, not an official invoice), Settings → General days before the due date.',
            self::InvoicePaymentReminder => 'On the "days before the due date" (Settings → Automation) that come after the notice was emailed, while it is unpaid.',
            self::InvoiceFirstOverdue => 'On the first of the "days after the due date" (Settings → Automation) while the notice is unpaid.',
            self::InvoiceSecondOverdue => 'On each later "days after the due date" while the notice is still unpaid.',
            self::InvoiceModified => 'When you send it from a payment notice\'s page (Send email).',
            self::PriceChange => 'When you schedule a price change with "Email the clients" ticked (Change price on a service, the Services list, a product or a TLD). One email per client lists their services.',
            self::Quote => 'When you press Send on a quote (Billing → Quotes), to the lead or client it is for.',
            self::HostingWelcome => 'When a hosting account is created on its server (Account → Create, or completing an order).',
            self::ServiceSuspension => 'When a service is suspended, by hand or automatically for non-payment.',
            self::ServiceUnsuspension => 'When a suspended service is unsuspended, e.g. after its payment is recorded.',
            self::CancellationApproved => 'When you approve a cancellation request a client sent from the client area (Services → Cancellation requests).',
            self::CancellationRejected => 'When you decline a cancellation request a client sent from the client area.',
            self::UpgradeApproved => 'When you approve a package change a client asked for from the client area (Services → Upgrade requests).',
            self::UpgradeRejected => 'When you decline a package change a client asked for from the client area.',
            self::UsageWarning => 'When the daily usage check finds a hosting account at Settings → Automation → Usage warning (85% by default) of its disk space or bandwidth limit; once a month for each.',
            self::DomainRegistration => 'When a domain is registered at the registrar.',
            self::DomainRenewal => 'When a domain is renewed at the registrar (recording its payment).',
            self::DomainTransferCompleted => 'When the daily domain sync finds that a transfer in has completed.',
            self::DomainRenewalNotice => 'Before the due date of domains ("days before the due date", Settings → Automation). One email per client lists every domain due.',
            self::HostingOffer, self::DomainTransferOffer, self::DomainWinBack => 'When you press Email on a row of Reports → Sales opportunities, after reading and editing the text there.',
            self::DomainExpired => 'Once, the day after a domain\'s expiry date at the registrar has passed without a renewal.',
            self::SslValidation => 'When an SSL certificate is ordered, reissued or renewed, and when its validation method changes: what the client does to prove the domain is theirs.',
            self::SslIssued => 'When an SSL certificate is issued (the hourly check finds it ready).',
            self::TicketOpened => 'When a client opens a support ticket, and when you open one for a client with "Email the client" ticked.',
            self::TicketReply => 'When you answer a support ticket (internal notes are never emailed).',
            self::TicketClosed => 'When a ticket that waited on the client closes by itself (Settings → Support → Close tickets after).',
        });
    }

    /** Whether the email ends with a "View in the client area" button (ClientAreaLinks); the login emails have their own link. */
    public function linksToClientArea(): bool
    {
        return ! in_array($this, [self::ClientSignup, self::ClientAreaInvite, self::ClientAreaLogin, self::EmailVerification, self::Quote], true);
    }

    /**
     * Whether it can hold a password or a sign-in link (login details, invitation, email confirmation, hosting
     * welcome): the client's Email history lists it without its text.
     */
    public function holdsSecrets(): bool
    {
        return in_array($this, [self::ClientAreaInvite, self::ClientAreaLogin, self::EmailVerification, self::HostingWelcome], true);
    }

    /** Whether it is sent until the admin switches it off: the reminders GaiaCP sends on its own are on. */
    public function enabledByDefault(): bool
    {
        return in_array($this, [
            self::RenewalReminder, self::OverdueNotice, self::DomainRenewalNotice, self::ClientAreaLogin, self::EmailVerification,
            self::InvoiceCreated, self::InvoicePaymentReminder, self::InvoiceFirstOverdue, self::InvoiceSecondOverdue,
            self::CancellationApproved, self::CancellationRejected, self::UpgradeApproved, self::UpgradeRejected, // the client asked and waits for the answer
            self::PriceChange, self::UsageWarning,
            self::SslValidation, self::SslIssued, // the client has to act, or gets what they paid for
            self::TicketOpened, self::TicketReply, self::TicketClosed,
        ], true);
    }

    /** @return array<string, string> placeholder name (without {$ }) => what it holds */
    public function placeholders(): array
    {
        $client = [
            'client_first_name' => 'Client first name',
            'client_last_name' => 'Client last name',
            'client_name' => 'Client full name',
            'client_company' => 'Client company (may be empty)',
            'client_email' => 'Client email',
        ];

        $company = [
            'company_name' => 'Your company name',
            'company_email' => 'Your company email',
            'company_phone' => 'Your company phone',
            'company_website' => 'Your company website',
            'signature' => 'Company name with phone, email and website, one per line',
            'payment_instructions' => 'How to pay: the enabled payment methods\' instructions (Settings → Bank transfer)',
        ];

        $reminder = [
            'services' => 'Table of the services in this email: service, due date, amount',
            'due_date' => 'Earliest due date in this email',
            'total' => 'Total of the listed amounts',
        ];

        $notice = [
            'invoice_number' => 'Payment notice number, e.g. PF-2026-0001',
            'invoice_date' => 'Payment notice date',
            'invoice_due_date' => 'Due date',
            'invoice_items' => 'Table of the items: description, period, amount',
            'invoice_subtotal' => 'Amount before tax',
            'invoice_tax' => 'Tax line, e.g. VAT 24%: €19.20 (empty when no tax, or why none is charged, e.g. a reverse charge)',
            'invoice_total' => 'Total',
            'days_overdue' => 'Days past the due date',
        ];

        $service = [
            'service' => 'Service name',
            'domain' => 'Domain of the service',
            'amount' => 'Recurring amount',
            'billing_cycle' => 'Billing cycle, e.g. Annually',
            'next_due_date' => 'Next due date',
        ];

        $domain = [
            'domain' => 'Domain name',
            'registration_years' => 'Years registered or renewed',
            'expiry_date' => 'Expiry date at the registrar (may be empty until synced)',
            'nameservers' => 'Nameservers, one per line',
            'amount' => 'Recurring amount',
            'next_due_date' => 'Next due date',
        ];

        $ticket = [
            'ticket_number' => 'Ticket number, e.g. T-000123',
            'ticket_subject' => 'Ticket subject',
            'ticket_department' => 'Department (may be empty)',
        ];

        return $client + match ($this) {
            self::ClientSignup => [],
            self::ClientAreaInvite => [
                'client_area_url' => 'Address of the client area (its login page)',
                'set_password_url' => 'Link to choose a password (works once, for 48 hours)',
            ],
            self::ClientAreaLogin => [
                'client_area_url' => 'Address of the client area (its login page)',
                'password' => 'The password you set when adding the client',
            ],
            self::EmailVerification => [
                'verification_url' => 'Link that confirms the email address (works for 60 minutes)',
            ],
            self::OrderConfirmation => [
                'order_number' => 'Order number',
                'order_date' => 'Order date',
                'order_items' => 'Table of the order items: item, billing cycle, amount',
                'order_total' => 'What the first payment of every item comes to, with tax where it applies',
            ],
            self::RenewalReminder, self::OverdueNotice, self::DomainRenewalNotice => $reminder,
            self::InvoiceCreated, self::InvoicePaymentReminder, self::InvoiceFirstOverdue, self::InvoiceSecondOverdue, self::InvoiceModified => $notice,
            self::Quote => [
                'quote_number' => 'Quote number, e.g. QT-2026-0001',
                'quote_valid_until' => 'The last day it can be accepted',
                'quote_intro' => 'The text you wrote above the quote\'s lines (may be empty)',
                'quote_items' => 'Table of the quote: item, billing cycle, first payment, renews at',
                'quote_total' => 'The first payment of everything in it, with tax where it applies',
                'quote_url' => 'Link to the quote\'s page, where it is accepted or declined (no login needed)',
            ],
            self::PriceChange => [
                'price_changes' => 'Table of the services: service, billing cycle, current price, new price',
                'effective_date' => 'The date from which renewals are billed at the new price',
            ],
            self::HostingWelcome => $service + [
                'package' => 'Package / service plan',
                'username' => 'Control panel username',
                'password' => 'Control panel password',
                'server_name' => 'Server name',
                'server_hostname' => 'Server hostname',
                'server_ip' => 'Server shared IP',
                'control_panel_url' => 'Control panel login address (cPanel :2083, Plesk :8443, HestiaCP :8083, DirectAdmin :2222)',
            ],
            self::ServiceSuspension => $service + ['suspension_reason' => 'Why it was suspended'],
            self::ServiceUnsuspension => $service,
            self::CancellationApproved, self::CancellationRejected => $service + [
                'cancellation_type' => 'When the client asked it to end: Immediately, or At the end of the billing period',
                'cancellation_date' => 'The date it ends: today, or its next due date',
            ],
            self::DomainRegistration, self::DomainRenewal, self::DomainTransferCompleted => $domain,
            self::DomainExpired => ['domain' => 'Domain name', 'expiry_date' => 'Expiry date at the registrar'],
            self::HostingOffer => ['domain' => 'Domain name'],
            self::UpgradeApproved => $service + [
                'new_product' => 'The package it moved to',
                'new_price' => 'Its new price per billing cycle',
                'effective_date' => 'The next due date, from which the new price is billed',
            ],
            self::UpgradeRejected => $service + ['new_product' => 'The package the client asked for'],
            self::UsageWarning => $service + [
                'package' => 'Package / service plan',
                'usage' => 'Table of what is near its limit: disk space or bandwidth, used, limit, share',
            ],
            self::DomainTransferOffer => ['domain' => 'Domain name', 'transfer_price' => 'Your transfer price for its TLD (empty when not set under Services → Domain pricing)'],
            self::DomainWinBack => [
                'domain' => 'Domain name',
                'expiry_date' => 'The date it expired',
                'renewal_price' => 'What renewing it costs now: its renewal price plus the late fee of its TLD',
            ],
            self::SslValidation => $service + [
                'validation_method' => 'How the domain is validated: DNS record, file on the website or email',
                'validation' => 'What to do, with the record, file or email address',
            ],
            self::SslIssued => $service + ['expiry_date' => 'The date the certificate expires'],
            self::TicketOpened => $ticket + ['ticket_message' => 'The ticket\'s first message'],
            self::TicketReply => $ticket + ['ticket_message' => 'The reply', 'admin_name' => 'Name of the admin who answered'],
            self::TicketClosed => $ticket,
        } + $company;
    }

    /** @return array<string, string> made-up values for the preview (tables are filled in by MailTemplates::sample) */
    public function sampleValues(): array
    {
        return [
            'service' => 'Starter (2.5GB)', 'domain' => 'example.gr', 'amount' => '€80.00', 'billing_cycle' => 'Annually',
            'next_due_date' => now()->addYear()->format(AppServiceProvider::dateFormat()), 'package' => 'starter', 'username' => 'examplegr',
            'password' => 'Xy7pQ2mN4vR8sT1w', 'server_name' => 'server1', 'server_hostname' => 'server1.example.com', 'server_ip' => '203.0.113.10',
            'control_panel_url' => 'https://server1.example.com:8443', 'suspension_reason' => 'Overdue on payment',
            'cancellation_type' => 'At the end of the billing period', 'cancellation_date' => now()->addYear()->format(AppServiceProvider::dateFormat()),
            'registration_years' => '1', 'expiry_date' => now()->addYear()->format(AppServiceProvider::dateFormat()), 'nameservers' => "ns1.example.com\nns2.example.com",
            'order_number' => '42', 'order_date' => now()->format(AppServiceProvider::dateFormat()), 'order_total' => '€95.00',
            'invoice_number' => 'PF-2026-0042', 'invoice_date' => now()->format(AppServiceProvider::dateFormat()), 'invoice_due_date' => now()->addDays(7)->format(AppServiceProvider::dateFormat()),
            'client_area_url' => 'https://clients.example.gr/login', 'set_password_url' => 'https://clients.example.gr/password-reset/reset?token=…',
            'verification_url' => 'https://clients.example.gr/email-verification/verify/7/…',
            'effective_date' => now()->addDays(30)->format(AppServiceProvider::dateFormat()), 'new_product' => 'Business (10GB)', 'new_price' => '€120.00', 'transfer_price' => '€12.00', 'renewal_price' => '€25.00',
            'ticket_number' => 'T-000042', 'ticket_subject' => 'Email not working', 'ticket_department' => 'Technical support',
            'ticket_message' => 'Hello, I cannot send email from my phone since this morning.', 'admin_name' => 'Maria',
            'validation_method' => 'DNS record (CNAME)', 'validation' => "Name: `_3f1c2a.example.gr`<br>\nType: `CNAME`<br>\nValue: `9a8b7c.6d5e4f.sectigo.com`",
            'quote_number' => 'QT-2026-0042', 'quote_valid_until' => now()->addDays(30)->format(AppServiceProvider::dateFormat()), 'quote_intro' => 'As we discussed, hosting for your shop with its domain.', 'quote_total' => '€95.00', 'quote_url' => 'https://clients.example.gr/quote/…',
            'invoice_subtotal' => '€95.00', 'invoice_tax' => '', 'invoice_total' => '€95.00', 'days_overdue' => '7',
        ];
    }

    /**
     * Whether there is built-in text in a language: English in this file, other languages in
     * `lang/<code>/mail_templates.php`.
     */
    public static function hasBuiltIn(?string $locale): bool
    {
        return $locale === null || $locale === 'en' || self::builtIn($locale) !== [];
    }

    /** @return array<string, mixed> a language's built-in text (`lang/<code>/mail_templates.php`), empty when there is none */
    private static function builtIn(string $locale): array
    {
        static $loaded = [];

        if (! preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale)) {
            return [];
        }

        return $loaded[$locale] ??= is_file($file = lang_path("{$locale}/mail_templates.php")) ? (array) require $file : [];
    }

    /** The built-in subject in a language (null or one without built-in text: English). */
    public function defaultSubject(?string $locale = null): string
    {
        if ($locale !== null && isset(self::builtIn($locale)[$this->value]['subject'])) {
            return self::builtIn($locale)[$this->value]['subject'];
        }

        return match ($this) {
            self::ClientSignup => 'Welcome to {$company_name}',
            self::OrderConfirmation => 'Order confirmation #{$order_number}',
            self::ClientAreaInvite => 'Your {$company_name} client area',
            self::ClientAreaLogin => 'Your {$company_name} client area login',
            self::EmailVerification => 'Confirm your email address for {$company_name}',
            self::RenewalReminder => 'Upcoming renewal of your services',
            self::OverdueNotice => 'Overdue: services awaiting payment',
            self::InvoiceCreated => 'Payment notice {$invoice_number}',
            self::InvoicePaymentReminder => 'Reminder: payment notice {$invoice_number} is due on {$invoice_due_date}',
            self::InvoiceFirstOverdue => 'Payment notice {$invoice_number} is overdue',
            self::InvoiceSecondOverdue => 'Second reminder: payment notice {$invoice_number} is overdue',
            self::InvoiceModified => 'Payment notice {$invoice_number} updated',
            self::PriceChange => 'Price change for your services from {$effective_date}',
            self::Quote => 'Quote {$quote_number} from {$company_name}',
            self::HostingWelcome => 'Your hosting account for {$domain} is ready',
            self::ServiceSuspension => 'Service suspended: {$service}',
            self::ServiceUnsuspension => 'Service reactivated: {$service}',
            self::CancellationApproved => 'Cancellation confirmed: {$service}',
            self::CancellationRejected => 'Your cancellation request for {$service}',
            self::DomainRegistration => 'Domain registered: {$domain}',
            self::DomainRenewal => 'Domain renewed: {$domain}',
            self::DomainTransferCompleted => 'Domain transfer completed: {$domain}',
            self::DomainRenewalNotice => 'Upcoming renewal of your domains',
            self::DomainExpired => 'Domain expired: {$domain}',
            self::HostingOffer => 'Hosting for {$domain}',
            self::UsageWarning => '{$domain} is close to its package limit',
            self::UpgradeApproved => 'Package changed: {$service}',
            self::UpgradeRejected => 'Your package change request for {$service}',
            self::DomainTransferOffer => 'Bring {$domain} to {$company_name}',
            self::DomainWinBack => 'You can still renew {$domain}',
            self::SslValidation => 'Action needed: validate {$domain} for its SSL certificate',
            self::SslIssued => 'Your SSL certificate for {$domain} is ready',
            self::TicketOpened => 'Support ticket [{$ticket_number}] {$ticket_subject}',
            self::TicketReply => 'Re: [{$ticket_number}] {$ticket_subject}',
            self::TicketClosed => 'Closed: [{$ticket_number}] {$ticket_subject}',
        };
    }

    /** The built-in body in a language (null or one without built-in text: English). */
    public function defaultBody(?string $locale = null): string
    {
        $text = $locale !== null ? self::builtIn($locale) : [];

        if (isset($text[$this->value]['body'], $text['greeting'], $text['closing'])) {
            return "{$text['greeting']}\n\n{$text[$this->value]['body']}\n\n{$text['closing']}";
        }

        $notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal: **{\$invoice_total}**, due on {\$invoice_due_date}.\n\n{\$payment_instructions}";

        $middle = match ($this) {
            self::ClientSignup => 'Thank you for choosing {$company_name}. Your account has been created with the email address {$client_email}.',
            self::ClientAreaInvite => "You can now see your services, domains and payment notices in the {\$company_name} client area.\n\n"
                ."[Choose your password]({\$set_password_url})\n\nThe link works once, for 48 hours. Your username is your email address, {\$client_email}. "
                .'Later, log in at {$client_area_url}, where "Forgot password?" sends you a new link.',
            self::ClientAreaLogin => "Welcome to {\$company_name}. You can now see your services, domains and payment notices in our client area.\n\n"
                ."Address: {\$client_area_url}\n\nUsername: {\$client_email}\n\nPassword: {\$password}\n\n"
                .'Please change the password from your profile after you log in. If you forget it, "Forgot password?" on the login page sends you a new link.',
            self::EmailVerification => "Thank you for signing up with {\$company_name}. Please confirm your email address to finish:\n\n"
                ."[Confirm my email address]({\$verification_url})\n\n"
                .'The link works for 60 minutes; after that, log in and ask for a new one. If you did not sign up, please ignore this email.',
            self::OrderConfirmation => "We have received your order #{\$order_number} of {\$order_date}.\n\n{\$order_items}\n\nTotal: **{\$order_total}**\n\n{\$payment_instructions}",
            self::RenewalReminder => "The following services are coming up for renewal.\n\n{\$services}\n\n{\$payment_instructions}\n\nIf you have already paid, please ignore this message.",
            self::OverdueNotice => "The following services are past their due date. Please arrange payment to avoid suspension.\n\n{\$services}\n\n{\$payment_instructions}\n\nIf you have already paid, please ignore this message.",
            self::InvoiceCreated => "Payment notice {\$invoice_number} of {\$invoice_date} for your services. This is a payment notice, not a tax invoice.\n\n{$notice}",
            self::InvoicePaymentReminder => "A reminder that payment notice {\$invoice_number} is due on {\$invoice_due_date}.\n\n{$notice}",
            self::InvoiceFirstOverdue => "Payment notice {\$invoice_number} was due on {\$invoice_due_date} and is {\$days_overdue} day(s) overdue.\n\n{$notice}",
            self::InvoiceSecondOverdue => "This is a second reminder that payment notice {\$invoice_number}, due on {\$invoice_due_date}, is still unpaid. Please pay it to avoid suspension of your services.\n\n{$notice}",
            self::InvoiceModified => "Payment notice {\$invoice_number} has been updated.\n\n{$notice}",
            self::PriceChange => "We are writing to let you know that the price of the following services changes for renewals due from **{\$effective_date}**.\n\n"
                ."{\$price_changes}\n\nUntil then nothing changes, and payment notices already issued stay as they are.\n\n"
                .'If you have any questions, or would like to talk about your plan, please reply to this email.',
            self::Quote => "Thank you for your interest. Here is our quote **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
                ."First payment: **{\$quote_total}**. The quote is valid until {\$quote_valid_until}.\n\n"
                .'[View and accept the quote]({$quote_url})'."\n\n".'If you have any questions, just reply to this email.',
            self::HostingWelcome => "Your hosting account for **{\$domain}** is ready.\n\n"
                ."Control panel: {\$control_panel_url}<br>\nUsername: {\$username}<br>\nPassword: {\$password}\n\n"
                ."Point your domain to our nameservers, or its A record to {\$server_ip}.\n\n"
                .'Package: {$package}, {$amount} {$billing_cycle}, next due on {$next_due_date}.',
            self::ServiceSuspension => "Your service **{\$service}** {\$domain} has been suspended.\n\nReason: {\$suspension_reason}\n\n{\$payment_instructions}",
            self::ServiceUnsuspension => 'Your service **{$service}** {$domain} is active again.',
            self::CancellationApproved => "We have received your cancellation request for **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
                .'The service ends on {$cancellation_date} and you will receive no more payment notices for it.',
            self::CancellationRejected => "We could not carry out your cancellation request for **{\$service}** {\$domain}, so the service stays as it is.\n\n"
                .'Please contact us if you would like to talk about it.',
            self::DomainRegistration => "The domain **{\$domain}** has been registered for {\$registration_years} year(s).\n\nNameservers:<br>\n{\$nameservers}",
            self::DomainRenewal => 'The domain **{$domain}** has been renewed for {$registration_years} year(s). Next due on {$next_due_date}.',
            self::DomainTransferCompleted => "The transfer of **{\$domain}** to us has completed. It expires on {\$expiry_date}.\n\nNameservers:<br>\n{\$nameservers}",
            self::DomainRenewalNotice => "The following domains are coming up for renewal.\n\n{\$services}\n\n{\$payment_instructions}\n\nIf you have already paid, please ignore this message.",
            self::UsageWarning => "Your hosting account **{\$service}** {\$domain} is close to the limits of its package ({\$package}):\n\n{\$usage}\n\n"
                .'When it reaches the limit, the website or email may stop working. To avoid that, you can free up space or move to a bigger package: press **Upgrade / downgrade** on the service\'s page in the client area, or reply to this email and we will suggest the one that suits you.',
            self::UpgradeApproved => "Your service **{\$service}** {\$domain} has moved to **{\$new_product}**, and its new package applies already.\n\n"
                .'Its new price is **{$new_price}** ({$billing_cycle}), billed from its next due date, {$effective_date}. If we charge the rest of the current period, you get a separate payment notice for it.',
            self::UpgradeRejected => "We could not move your service **{\$service}** {\$domain} to **{\$new_product}**, so it stays as it is.\n\n"
                .'Please contact us if you would like to talk about it.',
            self::HostingOffer => "Your domain **{\$domain}** is with us, but its website and email are hosted elsewhere, or not set up yet.\n\n"
                ."We can host them for you, with email addresses at your domain, and take care of everything in one place with one renewal date.\n\n"
                .'If you are interested, reply to this email and we will suggest the plan that suits you.',
            self::DomainTransferOffer => "We noticed that **{\$domain}** is registered with another provider. We can look after it together with your other services, with one renewal date and one payment notice.\n\n"
                ."Transfer price: {\$transfer_price}\n\n"
                .'If you would like us to transfer it, reply to this email and we will send you the steps.',
            self::DomainWinBack => 'The domain **{$domain}** expired on {$expiry_date} and has not been renewed. You can still renew it for {$renewal_price}. '
                ."After a few more days it becomes much more expensive to recover, and later anyone can register it.\n\n"
                .'If you would like to keep it, reply to this email and we will renew it straight away.',
            self::DomainExpired => "The domain **{\$domain}** expired on {\$expiry_date}. Renew it soon, or it may become available for anyone to register.\n\n{\$payment_instructions}",
            self::SslValidation => "To issue the SSL certificate for **{\$domain}**, the certificate authority needs proof that the domain is yours. Method: {\$validation_method}.\n\n{\$validation}\n\n"
                .'You can see this, pick another method or download the certificate once it is issued on the service\'s page in the client area.',
            self::SslIssued => "The SSL certificate for **{\$domain}** has been issued and is valid until {\$expiry_date}.\n\n"
                .'Download it from the service\'s page in the client area (certificate, CA bundle and private key) and install it on your website. If your website is hosted with us, reply to this email and we will install it for you.',
            self::TicketOpened => "Your support ticket **{\$ticket_number}**, \"{\$ticket_subject}\", is open:\n\n{\$ticket_message}\n\nWe answer on the ticket and by email, and you can follow it and reply from the client area.",
            self::TicketReply => "There is a new answer to your support ticket **{\$ticket_number}**, \"{\$ticket_subject}\":\n\n{\$ticket_message}\n\n"
                .'You can reply from the client area.',
            self::TicketClosed => "Your support ticket **{\$ticket_number}**, \"{\$ticket_subject}\", has been closed, as we have not heard from you for a few days.\n\n"
                .'If you still need help, reply to it in the client area and it opens again.',
        };

        return "Hello {\$client_first_name},\n\n{$middle}\n\nThank you,<br>\n{\$signature}";
    }
}
