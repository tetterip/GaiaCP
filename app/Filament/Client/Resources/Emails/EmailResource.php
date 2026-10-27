<?php

namespace App\Filament\Client\Resources\Emails;

use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\Emails\Pages\ListEmails;
use App\Models\EmailLog;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client's Email history (Settings → Client area → Email history): the emails sent to them, from the email log,
 * kept as long as Settings → Logging keeps it. Only the ones that reached them (no failed ones, no admin emails about
 * them, no Bcc); one that can hold a password or a sign-in link (MailTemplate::holdsSecrets()) is listed without its
 * text. In the client's menu, not in the sidebar.
 */
class EmailResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = EmailLog::class;

    protected static ?string $slug = 'emails';

    protected static ?string $modelLabel = 'email';

    protected static ?string $navigationLabel = 'Email history';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return (bool) config('gaiacp.client_area.email_history') && static::clientId() !== null;
    }

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId())
            ->where('to_client', true)
            ->where('status', 'sent');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i')
                    ->sortable(),
                TextColumn::make('subject')
                    ->placeholder(__('(no subject)'))
                    ->wrap()
                    ->searchable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (EmailLog $record) => $record->subject ?: __('(no subject)'))
                    ->modalContent(fn (EmailLog $record) => view('filament.client.email', ['entry' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('3xl'),
            ])
            ->emptyStateHeading(__('No emails yet'))
            ->emptyStateDescription(__('Emails we send you show here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmails::route('/'),
        ];
    }
}
