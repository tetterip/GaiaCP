<?php

namespace App\Filament\Client\Pages;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Invoice;
use App\Models\Service;
use App\Support\Greeting;
use App\Support\MailTemplates;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** The client area's home page: what they owe, what is active and what renews next. */
class Dashboard extends Page
{
    use TranslatesPageLabels;

    /** How many upcoming renewals Home lists. */
    public const UPCOMING = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Home';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.client.dashboard';

    /** "Good afternoon, Maria", like the admin dashboard; the page title without a first name. */
    public function getHeading(): string|Htmlable
    {
        $name = trim((string) $this->client()?->first_name);

        return $name === '' ? $this->getTitle() : Greeting::for($name);
    }

    public function getSubheading(): ?string
    {
        return now()->locale(app()->getLocale())->translatedFormat('l j F');
    }

    public function client(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    /**
     * Messages to clients shown in the client area today that match this client, newest first, in their language.
     *
     * @return list<array{subject: string, body: HtmlString}>
     */
    public function announcements(): array
    {
        $client = $this->client();

        if (! $client) {
            return [];
        }

        $values = MailTemplates::common($client);

        return ClientMessage::query()->showing()->latest()->get()
            ->filter(fn (ClientMessage $message) => $message->matches($client))
            ->map(function (ClientMessage $message) use ($client, $values) {
                $text = $message->text($client->preferredLocale());

                return [
                    'subject' => html_entity_decode(strip_tags(MailTemplates::fill($text['subject'], $values)), ENT_QUOTES),
                    // No HTML: written by any admin with the Clients area, and shown inside the client area (also to admins looking as the client)
                    'body' => new HtmlString(Str::markdown(MailTemplates::fill($text['body'], $values), ['html_input' => 'strip', 'allow_unsafe_links' => false])),
                ];
            })
            ->values()->all();
    }

    /** @return Collection<int, Invoice> unpaid payment notices, the oldest due first */
    public function unpaidNotices(): Collection
    {
        return PaymentNoticeResource::getEloquentQuery()->unpaid()->orderBy('due_date')->get();
    }

    /** Active services and domains shown in the client area. */
    public function activeCount(): int
    {
        return $this->visibleServices()->where('status', ServiceStatus::Active)->count();
    }

    /** @return Collection<int, Service> the next renewals of services and domains still billed */
    public function upcoming(): Collection
    {
        return $this->visibleServices()
            ->billable()
            ->where('auto_terminate', false)
            ->orderBy('next_due_date')
            ->limit(self::UPCOMING)
            ->get();
    }

    /** @return list<array{label: string, url: string, icon: Heroicon}> Home's shortcuts to the pages clients use most */
    public function shortcuts(): array
    {
        return array_values(array_filter([
            Order::canAccess() ? ['label' => __('Order'), 'url' => Order::getUrl(), 'icon' => Heroicon::OutlinedShoppingCart] : null,
            ['label' => __('Payment notices'), 'url' => PaymentNoticeResource::getUrl(), 'icon' => Heroicon::OutlinedDocumentText],
            Credit::canAccess() ? ['label' => __('Credit'), 'url' => Credit::getUrl(), 'icon' => Heroicon::OutlinedWallet] : null,
            ['label' => __('Services'), 'url' => ServiceResource::getUrl(), 'icon' => Heroicon::OutlinedServerStack],
            ['label' => __('Domains'), 'url' => DomainResource::getUrl(), 'icon' => Heroicon::OutlinedGlobeAlt],
            ['label' => __('Your profile'), 'url' => filament()->getProfileUrl(), 'icon' => Heroicon::OutlinedUserCircle],
            KnowledgeBase::canAccess() ? ['label' => __('Knowledge base'), 'url' => KnowledgeBase::getUrl(), 'icon' => Heroicon::OutlinedBookOpen] : null,
            ['label' => __('Help'), 'url' => Help::getUrl(), 'icon' => Heroicon::OutlinedQuestionMarkCircle],
        ]));
    }

    public function serviceUrl(Service $service): string
    {
        return $service->type === ServiceType::Domain
            ? DomainResource::getUrl('view', ['record' => $service])
            : ServiceResource::getUrl('view', ['record' => $service]);
    }

    /** @return Builder<Service> */
    private function visibleServices()
    {
        return Service::query()->where('client_id', $this->client()?->id)->clientVisible();
    }
}
