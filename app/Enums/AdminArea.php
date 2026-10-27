<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * The parts of the panel an admin who is not a super admin can be given (`users.admin_areas`). Each page and
 * resource names its area in `adminArea()` (App\Filament\Concerns\LimitedToAdminArea); one with none (Admin users,
 * Servers, Registrars, every System setting, Plugins) is for super admins only. The dashboard, Help and the profile are
 * for everyone.
 */
enum AdminArea: string implements HasDescription, HasLabel
{
    case Clients = 'clients';
    case Services = 'services';
    case Billing = 'billing';
    case Support = 'support';
    case Sales = 'sales';
    case Reports = 'reports';
    case ActivityLog = 'activity_log';
    case BusinessSettings = 'business_settings';
    case ServiceSettings = 'service_settings';
    case EmailSettings = 'email_settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Clients => __('Clients and orders'),
            self::Services => __('Hosting, domains and other services'),
            self::Billing => __('Payment notices and payments'),
            self::Support => __('Support tickets'),
            self::Sales => __('Sales: leads and quotes'),
            self::Reports => __('Reports'),
            self::ActivityLog => __('Activity log'),
            self::BusinessSettings => __('Settings: business'),
            self::ServiceSettings => __('Settings: products and domains'),
            self::EmailSettings => __('Settings: email'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Clients => __('Clients, their details and orders'),
            self::Services => __('Hosting accounts, domains and other services, with their actions'),
            self::Billing => __('Payment notices and recorded payments'),
            self::Support => __('Tickets, departments and canned replies'),
            self::Sales => __('Prospective clients, their notes and follow-ups, and quotes'),
            self::Reports => __('Income and service reports'),
            self::ActivityLog => __('Everything done in the panel and by the daily run'),
            self::BusinessSettings => __('Company, billing, tax and ΑΑΔΕ'),
            self::ServiceSettings => __('Products and domain settings'),
            self::EmailSettings => __('Mail server, layout and email templates'),
        };
    }
}
