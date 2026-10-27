<?php

namespace App\Filament\Resources\EmailLogs;

use App\Enums\MailTemplate;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Every email sent or failed, with its HTML as sent (App\Models\EmailLog). Settings → System. Read-only. */
class EmailLogResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 102;

    protected static ?string $navigationLabel = 'Email log';

    protected static ?string $modelLabel = 'email';

    protected static ?string $slug = 'email-log';

    protected static bool $isGloballySearchable = false;

    public static function settingsDescription(): string
    {
        return __('Every email sent to clients and to you, with its text, and the ones that failed with the reason.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('to')->searchable()->limit(40),
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (EmailLog $record) => $record->client?->name)
                    ->url(fn (EmailLog $record) => $record->client_id ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null)
                    ->placeholder('—'),
                TextColumn::make('subject')->wrap()->searchable()->description(fn (EmailLog $record) => $record->template?->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn (EmailLog $record) => $record->error),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['sent' => __('Sent'), 'failed' => __('Failed')]),
                SelectFilter::make('template')
                    ->label('Email')
                    ->options(collect(MailTemplate::cases())->mapWithKeys(fn (MailTemplate $t) => [$t->value => $t->label()])->all()),
                SelectFilter::make('client')->relationship('client', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn ($client) => $client->name)
                    ->searchable(['first_name', 'last_name', 'company']),
            ])
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (EmailLog $record) => $record->subject ?: __('(no subject)'))
                    ->modalContent(fn (EmailLog $record) => view('filament.logs.email-log-entry', ['entry' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('4xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
        ];
    }
}
