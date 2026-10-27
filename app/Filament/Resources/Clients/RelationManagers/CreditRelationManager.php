<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Payments\PaymentMethods;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The client's credit ledger, newest first; only App\Credit\ClientCredit writes it (Credit menu above). */
class CreditRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'creditTransactions';

    protected static ?string $title = 'Credit';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedWallet;

    /** For admins with billing, on clients that have used credit. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InvoiceResource::canAccess() && $ownerRecord->creditTransactions()->exists();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        return Money::format((int) $ownerRecord->credit_cents, $ownerRecord->currencyCode());
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
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('description')
                    ->description(fn (CreditTransaction $record) => collect([PaymentMethods::label($record->method), $record->reference, $record->user?->name])->filter()->join(' · '))
                    ->wrap(),
                TextColumn::make('invoice.number')
                    ->label('Payment notice')
                    ->url(fn (CreditTransaction $record) => $record->invoice_id ? InvoiceResource::getUrl('view', ['record' => $record->invoice_id]) : null)
                    ->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (CreditTransaction $record) => ($record->amount_cents > 0 ? '+' : '−').Money::format(abs($record->amount_cents), $record->currency))
                    ->color(fn (CreditTransaction $record) => $record->amount_cents > 0 ? 'success' : null)
                    ->alignEnd(),
                TextColumn::make('balance_cents')
                    ->label('Balance')
                    ->formatStateUsing(fn (CreditTransaction $record) => Money::format($record->balance_cents, $record->currency))
                    ->alignEnd(),
            ]);
    }
}
