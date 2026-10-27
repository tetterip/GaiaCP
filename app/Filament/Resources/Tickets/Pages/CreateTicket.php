<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Tickets;
use App\Enums\TicketPriority;
use App\Filament\Actions\AiTextActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Tickets\TicketFiles;
use App\Models\Client;
use App\Models\Service;
use App\Models\TicketDepartment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** An admin opens a ticket for a client (from Tickets or the client page's Tickets tab); it starts waiting on the client. */
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
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn () => Client::query()->orderBy('last_name')->get()->mapWithKeys(fn (Client $c) => [$c->id => $c->displayName()]))
                        ->searchable()
                        ->required()
                        ->default(fn () => request()->integer('client') ?: null)
                        ->live(),
                    Select::make('service_id')
                        ->label('Service')
                        ->placeholder(__('None'))
                        ->options(fn (Get $get) => $get('client_id')
                            ? Service::query()->where('client_id', $get('client_id'))->orderBy('label')->get()
                                ->mapWithKeys(fn (Service $s) => [$s->id => trim($s->label.' '.($s->domain !== $s->label ? $s->domain : ''))])
                            : []),
                    Select::make('department_id')
                        ->label('Department')
                        ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id')),
                    Select::make('priority')
                        ->options(TicketPriority::class)
                        ->default(TicketPriority::Medium->value)
                        ->required(),
                    TextInput::make('subject')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),
                    AiTextActions::on(Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(8)
                        ->maxLength(20000)
                        ->columnSpanFull()),
                    TicketFiles::make()->columnSpanFull(),
                    Toggle::make('email_client')
                        ->label('Email the client')
                        ->helperText(__('Sends the Support Ticket Opened email with this message, if it is on under Email templates.'))
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(Tickets::class)->open(
            Client::query()->findOrFail($data['client_id']),
            auth()->user(),
            $data['subject'],
            $data['body'],
            filled($data['department_id'] ?? null) ? TicketDepartment::query()->find($data['department_id']) : null,
            TicketPriority::from($data['priority'] instanceof TicketPriority ? $data['priority']->value : $data['priority']),
            filled($data['service_id'] ?? null) ? Service::query()->find($data['service_id']) : null,
            TicketFiles::from($data),
            (bool) ($data['email_client'] ?? false),
        );
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
