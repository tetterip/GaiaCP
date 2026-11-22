<?php

namespace App\Filament\Client\Resources\Tickets\Pages;

use App\Actions\Tickets;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Filament\Tickets\TicketFiles;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Support\KnowledgeBase;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** The client opens a ticket: department, the service it is about, the priority (Settings → Support), Urgent while the month's limit allows, and files. */
class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('Open a ticket');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('department_id')
                        ->label('Department')
                        ->options(fn () => TicketDepartment::query()->clientVisible()->ordered()->pluck('name', 'id'))
                        ->required(fn () => TicketDepartment::query()->clientVisible()->exists())
                        ->visible(fn () => TicketDepartment::query()->clientVisible()->exists()),
                    Toggle::make('urgent')
                        ->label('Urgent: a service stopped working')
                        ->helperText(fn () => self::urgentLeft() > 0
                            ? trans_choice('You can mark :count more ticket urgent this month.|You can mark :count more tickets urgent this month.', self::urgentLeft())
                            : __('You used this month\'s urgent tickets; this one goes in as usual.'))
                        ->disabled(fn () => self::urgentLeft() === 0)
                        ->live()
                        ->visible(fn () => Tickets::urgentLimit() > 0 && self::services()->where('status', ServiceStatus::Active)->exists())
                        ->columnSpanFull(),
                    Select::make('service_id')
                        ->label('About the service')
                        ->placeholder(fn (Get $get) => $get('urgent') ? __('Choose') : __('None in particular'))
                        ->options(fn (Get $get) => self::services()->when($get('urgent'), fn (Builder $query) => $query->where('status', ServiceStatus::Active))->get()
                            ->mapWithKeys(fn (Service $s) => [$s->id => trim($s->label.' '.($s->domain !== $s->label ? $s->domain : ''))]))
                        ->required(fn (Get $get) => (bool) $get('urgent'))
                        ->helperText(fn (Get $get) => $get('urgent') ? __('The service that stopped working.') : null),
                    Select::make('priority')
                        ->options(TicketPriority::clientOptions())
                        ->default(TicketPriority::Medium->value)
                        ->required()
                        ->visible(fn (Get $get) => (bool) config('gaiacp.support.client_priority') && ! $get('urgent')),
                    TextInput::make('subject')
                        ->required()
                        ->maxLength(200)
                        ->live(debounce: 700)
                        ->columnSpanFull(),
                    // Knowledge base articles that may already answer it
                    View::make('filament.client.kb-suggestions')
                        ->viewData(fn (Get $get) => ['articles' => KnowledgeBase::suggestions($get('subject'))])
                        ->visible(fn (Get $get) => KnowledgeBase::suggestions($get('subject'))->isNotEmpty())
                        ->columnSpanFull(),
                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(8)
                        ->maxLength(20000)
                        ->columnSpanFull(),
                    TicketFiles::make()->columnSpanFull(),
                ]),
        ]);
    }

    /** @return Builder<Service> the client's services the client area shows, not cancelled */
    private static function services(): Builder
    {
        return Service::query()->where('client_id', TicketResource::clientId())->clientVisible()
            ->where('status', '!=', ServiceStatus::Cancelled)->orderBy('label');
    }

    /** Urgent tickets the client may still open this month. */
    private static function urgentLeft(): int
    {
        $client = TicketResource::client();

        return $client ? Tickets::urgentLeft($client) : 0;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $priority = $data['priority'] ?? null;
        $service = filled($data['service_id'] ?? null) ? self::services()->find($data['service_id']) : null;

        if (! config('gaiacp.support.client_priority') || ! array_key_exists((string) ($priority instanceof TicketPriority ? $priority->value : $priority), TicketPriority::clientOptions())) {
            $priority = null;
        }

        $open = fn (?TicketPriority $priority) => app(Tickets::class)->open(
            TicketResource::client(),
            filament()->auth()->user(),
            $data['subject'],
            $data['body'],
            filled($data['department_id'] ?? null) ? TicketDepartment::query()->clientVisible()->find($data['department_id']) : null,
            $priority,
            $service,
            TicketFiles::from($data),
            asClient: true,
        );

        if (empty($data['urgent'])) {
            return $open($priority === null ? null : ($priority instanceof TicketPriority ? $priority : TicketPriority::from($priority)));
        }

        $refused = fn () => ValidationException::withMessages(['data.urgent' => __('This ticket can\'t be marked urgent.')]);

        // One urgent ticket at a time per client, so two sent together can't both pass the month's limit
        try {
            return Cache::lock('urgent-ticket:'.TicketResource::client()?->id, 10)->block(5, function () use ($open, $service, $refused) {
                if (self::urgentLeft() === 0 || $service?->status !== ServiceStatus::Active) {
                    throw $refused();
                }

                return $open(TicketPriority::Urgent);
            });
        } catch (LockTimeoutException) {
            throw $refused();
        }
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Send'));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Your ticket was sent. We will answer here and by email.');
    }
}
