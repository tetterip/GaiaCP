<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\Leads;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Lead;
use App\Support\Region;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Symfony\Component\Intl\Countries;

/**
 * A lead with its notes below. Convert to client makes it a client (or links it to the client who already has its
 * email), Mark as lost closes it with a reason, Reopen brings a lost one back.
 *
 * @property Lead $record
 */
class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openClient')
                ->label('Open client')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->visible(fn () => LeadResource::clientUrl($this->record) !== null)
                ->url(fn () => LeadResource::clientUrl($this->record)),
            Action::make('quote')
                ->label('New quote')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('gray')
                ->visible(fn () => $this->record->status->isOpen() && QuoteResource::canCreate())
                ->url(fn () => QuoteResource::getUrl('create', ['lead' => $this->record->id])),
            Action::make('convert')
                ->label('Convert to client')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => $this->record->client_id === null)
                ->modalHeading(__('Convert to client'))
                ->modalSubmitActionLabel(__('Convert'))
                ->fillForm(fn () => [
                    'first_name' => $this->record->first_name,
                    'last_name' => $this->record->last_name,
                    'company' => $this->record->company,
                    'email' => $this->record->email,
                    'phone' => $this->record->phone,
                    'country' => $this->record->country ?: Region::country(),
                    'invite' => true,
                ])
                ->schema([
                    Text::make(fn (Get $get) => ($client = Leads::existingClient($get('email')))
                        ? __(':name already has this email: the lead is linked to them, no new client is made.', ['name' => $client->name])
                        : __('A new client is made with these details. Complete the address later on the client\'s page.'))
                        ->columnSpanFull(),
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextInput::make('first_name')->required()->maxLength(255),
                        TextInput::make('last_name')->maxLength(255),
                        TextInput::make('company')->maxLength(255),
                        TextInput::make('email')->email()->required()->maxLength(255)->live(onBlur: true),
                        TextInput::make('phone')->tel()->maxLength(50),
                        Select::make('country')
                            ->options(fn () => Countries::getNames('en'))
                            ->searchable()
                            ->required(),
                    ]),
                    Toggle::make('invite')
                        ->label('Email a client area invitation')
                        ->hidden(fn (Get $get) => Leads::existingClient($get('email')) !== null)
                        ->columnSpanFull(),
                ])
                ->modalWidth('2xl')
                ->action(function (array $data) {
                    $existing = Leads::existingClient($data['email']);

                    try {
                        $client = app(Leads::class)->convert($this->record, $data, (bool) ($data['invite'] ?? false), $existing);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title($existing ? __('Linked to :name', ['name' => $client->name]) : __('Client :name created', ['name' => $client->name]))
                        ->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('lost')
                ->label('Mark as lost')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->visible(fn () => $this->record->status->isOpen())
                ->modalHeading(__('Mark as lost'))
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->placeholder(__('Too expensive, went elsewhere, no answer…'))
                        ->rows(2)
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    app(Leads::class)->markLost($this->record, $data['reason'] ?? null);
                    Notification::make()->success()->title(__('Marked as lost'))->send();
                    $this->refreshFormData(['status', 'next_contact_on']);
                }),
            Action::make('reopen')
                ->label('Reopen')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn () => $this->record->status === LeadStatus::Lost)
                ->requiresConfirmation()
                ->action(function () {
                    app(Leads::class)->reopen($this->record);
                    Notification::make()->success()->title(__('Reopened'))->send();
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make()
                ->hidden(fn () => $this->record->client_id !== null),
        ];
    }
}
