<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\MailTemplate;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Models\Client;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The client page's Emails tab: the Email log rows of this client (sent to them, or to an admin about them), newest
 * first, with the text as sent. Shown to admins who may open Settings → Email log, once the client has an email.
 */
class EmailsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'emailLogs';

    protected static ?string $title = 'Emails';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedEnvelope;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Client $ownerRecord */
        return EmailLogResource::canAccess() && $ownerRecord->emailLogs()->exists();
    }

    /** Emails that could not be sent. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $failed = $ownerRecord->emailLogs()->where('status', 'failed')->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return __('Not sent');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('subject')
                    ->wrap()
                    ->searchable()
                    ->placeholder(__('(no subject)'))
                    ->description(fn (EmailLog $record) => $record->template?->label()),
                TextColumn::make('to')->searchable()->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'sent' ? __('Sent') : __('Failed'))
                    ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn (EmailLog $record) => $record->error),
            ])
            ->filters([
                TernaryFilter::make('to_client')->label('Sent to the client'),
                SelectFilter::make('status')->options(['sent' => __('Sent'), 'failed' => __('Failed')]),
                SelectFilter::make('template')
                    ->label('Email')
                    ->options(collect(MailTemplate::cases())->mapWithKeys(fn (MailTemplate $t) => [$t->value => $t->label()])->all()),
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
}
