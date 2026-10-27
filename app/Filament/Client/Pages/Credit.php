<?php

namespace App\Filament\Client\Pages;

use App\Credit\AddFunds;
use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * The client's credit: the balance, "Add funds" (an "Add funds" payment notice, App\Credit\AddFunds) and the history.
 * Shown while Settings → Client credit lets clients add funds, or once the client has any credit history.
 */
class Credit extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $title = 'Credit';

    protected static ?string $slug = 'credit';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected string $view = 'filament.client.credit';

    public static function canAccess(): bool
    {
        $client = self::currentClient();

        return $client !== null && (ClientCredit::enabled() || $client->creditTransactions()->exists());
    }

    public static function currentClient(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    public function client(): Client
    {
        return self::currentClient();
    }

    public function balance(): string
    {
        return Money::format((int) $this->client()->fresh()->credit_cents, $this->client()->currencyCode());
    }

    protected function getHeaderActions(): array
    {
        return [$this->addFunds()];
    }

    public function addFunds(): Action
    {
        return Action::make('addFunds')
            ->label('Add funds')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => ClientCredit::enabled())
            ->disabled(fn () => ClientCredit::cannotAddFunds($this->client()) !== null)
            ->tooltip(fn () => ClientCredit::cannotAddFunds($this->client()))
            ->modalDescription(fn () => __('We create a payment notice for the amount. Once it is paid, by card or bank transfer, the amount is added to your credit.'))
            ->schema(function () {
                $client = $this->client();
                $currency = $client->currencyCode();
                $min = ClientCredit::limit('min_deposit', $client);
                $max = ClientCredit::maxDeposit($client);

                return [
                    TextInput::make('amount')
                        ->required()
                        ->prefix($currency)
                        ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                        ->default(Money::toDecimal($min))
                        ->helperText(__('From :min to :max.', ['min' => Money::format($min, $currency), 'max' => Money::format($max, $currency)])),
                ];
            })
            ->modalSubmitActionLabel(__('Create payment notice'))
            ->action(function (array $data) {
                try {
                    $notice = app(AddFunds::class)->handle($this->client(), Money::toCents($data['amount']));
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Not created'))->body($e->getMessage())->danger()->send();

                    return;
                }

                $this->redirect(PaymentNoticeResource::getUrl('view', ['record' => $notice]));
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CreditTransaction::query()->where('client_id', $this->client()->id)->with('invoice'))
            ->defaultSort('id', 'desc')
            ->heading(__('History'))
            ->emptyStateHeading(__('No credit yet'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date(),
                TextColumn::make('type')
                    ->label('What')
                    ->badge()
                    ->description(fn (CreditTransaction $record) => $record->type === CreditType::Adjustment ? $record->description : null),
                TextColumn::make('invoice.number')
                    ->label('Payment notice')
                    ->url(fn (CreditTransaction $record) => $record->invoice_id && $record->invoice?->client_id === $this->client()->id
                        ? PaymentNoticeResource::getUrl('view', ['record' => $record->invoice_id]) : null)
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
