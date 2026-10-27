<?php

namespace App\Filament\Client\Resources\Quotes;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\Quotes\Pages\ListQuotes;
use App\Models\Quote;
use App\Providers\AppServiceProvider;
use App\Support\TermsOfService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The quotes sent to the client (not drafts), with their page (print, lines, terms) and Accept / Decline right here,
 * as on that page (App\Actions\Quotes). In the menu only once the client has one.
 */
class QuoteResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Quote::class;

    protected static ?string $slug = 'quotes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 6; // after Credit

    public static function getNavigationGroup(): string
    {
        return __('Billing');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::clientId() !== null && static::getEloquentQuery()->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::getEloquentQuery()->where('status', QuoteStatus::Sent)->whereDate('valid_until', '>=', today())->count();

        return $open > 0 ? (string) $open : null;
    }

    /** Theirs, or written for the lead that became them; a draft is not shown before it is sent. */
    protected static function scopeToClient(Builder $query): Builder
    {
        $client = static::clientId();

        return $query->where('status', '!=', QuoteStatus::Draft)
            ->where(fn (Builder $query) => $query->where('client_id', $client)
                ->orWhere(fn (Builder $query) => $query->whereNull('client_id')->whereHas('lead', fn (Builder $lead) => $lead->where('client_id', $client))));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('sent_at')
                    ->label('Date')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
                TextColumn::make('total')
                    ->label('First payment')
                    ->state(fn (Quote $record) => $record->money($record->totals()['total'])),
                TextColumn::make('valid_until')
                    ->date(AppServiceProvider::dateFormat()),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Quote $record) => $record->url())
            ->recordActions([
                Action::make('accept')
                    ->label('Accept')
                    ->icon(Heroicon::OutlinedCheck)
                    ->button()
                    ->visible(fn (Quote $record) => $record->acceptable())
                    ->modalHeading(fn (Quote $record) => __('Accept quote :number', ['number' => $record->number]))
                    ->modalDescription(fn (Quote $record) => __('First payment: :total', ['total' => $record->totalText()]).' '.__('Accepting places the order: you get a payment notice by email with how to pay.'))
                    ->modalSubmitActionLabel(__('Accept the quote'))
                    ->schema([TermsOfService::checkbox()])
                    ->action(function (Quote $record, array $data) {
                        try {
                            app(Quotes::class)->accept($record, (bool) ($data['accept_terms'] ?? false), request()->ip());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('Thank you, your order is placed'))
                            ->body(__('Its payment notice was emailed to you, with how to pay.'))
                            ->send();
                    }),
                Action::make('decline')
                    ->label('Decline')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (Quote $record) => $record->acceptable())
                    ->modalHeading(fn (Quote $record) => __('Decline quote :number', ['number' => $record->number]))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Why? (optional, it helps us)'))
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->action(function (Quote $record, array $data) {
                        try {
                            app(Quotes::class)->decline($record, $data['reason'] ?? null);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Quote declined'))->send();
                    }),
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->url(fn (Quote $record) => $record->url()),
            ])
            ->emptyStateHeading(__('No quotes yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
        ];
    }
}
