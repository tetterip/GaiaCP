<?php

namespace App\Mail;

use App\Enums\MailTemplate;
use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\MailTemplates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * The daily reminder to one client: the upcoming renewal notice, the overdue notice when any
 * listed service is overdue, or the upcoming domain renewal notice for a list of domains only.
 */
class RenewalReminderMail extends ClientMail
{
    /**
     * @param  list<array{service: Service, kind: string}>  $items
     */
    public function __construct(
        Client $client,
        public array $items,
        public CarbonImmutable $today,
    ) {
        parent::__construct(MailTemplate::RenewalReminder, $client);
        $this->mailTemplate = $this->pickTemplate();
    }

    /** An example email for the template preview, with made-up client and services. */
    public static function sample(MailTemplate $template, ?string $locale = null): self
    {
        $today = CarbonImmutable::today();
        $service = fn (string $type, string $label, ?string $domain, int $cents, int $days) => Service::make([
            'type' => $type, 'label' => $label, 'domain' => $domain, 'price_cents' => $cents, 'currency' => config('gaiacp.currency'),
            'next_due_date' => $today->addDays($days),
        ]);

        $items = match ($template) {
            MailTemplate::DomainRenewalNotice => [
                ['service' => $service('domain', 'Domain', 'example.gr', 1500, 7), 'kind' => 'before:7'],
                ['service' => $service('domain', 'Domain', 'example.com', 1400, 14), 'kind' => 'before:14'],
            ],
            default => [
                ['service' => $service('hosting', 'Starter', 'example.gr', 8000, $template === MailTemplate::OverdueNotice ? -7 : 7), 'kind' => $template === MailTemplate::OverdueNotice ? 'overdue:7' : 'before:7'],
                ['service' => $service('maintenance', 'Website maintenance', null, 12000, 14), 'kind' => 'before:14'],
            ],
        };

        return new self(self::sampleClient(), $items, $today);
    }

    public function hasOverdue(): bool
    {
        return collect($this->items)->contains(fn (array $item) => str_starts_with($item['kind'], 'overdue:'));
    }

    private function pickTemplate(): MailTemplate
    {
        return match (true) {
            $this->hasOverdue() => MailTemplate::OverdueNotice,
            collect($this->items)->every(fn (array $item) => $item['service']->type === ServiceType::Domain) => MailTemplate::DomainRenewalNotice,
            default => MailTemplate::RenewalReminder,
        };
    }

    /** @return array<string, string|HtmlString|null> */
    public function placeholders(): array
    {
        $services = collect($this->items)->map(fn (array $item) => $item['service']);

        $rows = collect($this->items)->map(function (array $item) {
            $service = $item['service'];
            $label = $service->domain ? "{$service->label} ({$service->domain})" : $service->label;

            if (str_starts_with($item['kind'], 'overdue:')) {
                $label .= ' — '.__('overdue');
            }

            return [$label, $service->next_due_date->format(AppServiceProvider::dateFormat()), Money::format($service->price_cents, $service->currency)];
        });

        return MailTemplates::common($this->client) + [
            'services' => MailTemplates::table([__('Service'), __('Due date'), __('Amount')], $rows),
            'due_date' => $services->min('next_due_date')?->format(AppServiceProvider::dateFormat()),
            'total' => $services->groupBy('currency')
                ->map(fn ($group, $currency) => Money::format($group->sum('price_cents'), $currency))
                ->join(' + '),
        ];
    }
}
