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
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The client opens a ticket: department, the service it is about, the priority (Settings → Support) and files. */
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
                    Select::make('service_id')
                        ->label('About the service')
                        ->placeholder(__('None in particular'))
                        ->options(fn () => self::services()->get()
                            ->mapWithKeys(fn (Service $s) => [$s->id => trim($s->label.' '.($s->domain !== $s->label ? $s->domain : ''))])),
                    Select::make('priority')
                        ->options(TicketPriority::class)
                        ->default(TicketPriority::Medium->value)
                        ->required()
                        ->visible(fn () => (bool) config('gaiacp.support.client_priority')),
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

    protected function handleRecordCreation(array $data): Model
    {
        $priority = $data['priority'] ?? null;

        return app(Tickets::class)->open(
            TicketResource::client(),
            filament()->auth()->user(),
            $data['subject'],
            $data['body'],
            filled($data['department_id'] ?? null) ? TicketDepartment::query()->clientVisible()->find($data['department_id']) : null,
            config('gaiacp.support.client_priority') && $priority ? ($priority instanceof TicketPriority ? $priority : TicketPriority::from($priority)) : null,
            filled($data['service_id'] ?? null) ? self::services()->find($data['service_id']) : null,
            TicketFiles::from($data),
            asClient: true,
        );
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
