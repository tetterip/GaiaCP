<?php

namespace App\Filament\Client\Resources\Payments;

use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Payments\PaymentMethods;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Payments the client made, for every service of theirs (like their payment notices), free renewals left out. */
class PaymentResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string
    {
        return __('Billing');
    }

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId())
            ->where('amount_cents', '>', 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service'))
            ->columns([
                TextColumn::make('paid_on')
                    ->label('Date')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
                TextColumn::make('service.label')
                    ->label('For')
                    ->description(fn (Payment $record) => $record->service->domain !== $record->service->label ? $record->service->domain : null),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->state(fn (Payment $record) => Money::format($record->amount_cents, $record->currency))
                    ->sortable(),
                TextColumn::make('method')
                    ->formatStateUsing(fn (?string $state) => PaymentMethods::label($state)),
            ])
            ->defaultSort('paid_on', 'desc')
            ->recordActions([])
            ->emptyStateHeading(__('No payments yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
