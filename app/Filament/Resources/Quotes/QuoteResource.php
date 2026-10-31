<?php

namespace App\Filament\Resources\Quotes;

use App\Actions\Quotes;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Filament\Actions\AiTextActions;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Quote;
use App\Providers\AppServiceProvider;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\Tax;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Billing → Quotes (App\Actions\Quotes): offers to a lead or a client, written with the items of New order and one-off
 * lines, sent by email with the link to their page, where they are accepted (the order is placed) or declined.
 */
class QuoteResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Main';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'number';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Sales;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            // Once answered (or expired) a quote stays as it was sent: Duplicate makes a new one to change.
            ->disabled(fn (?Quote $record) => $record !== null && ! $record->status->isOpen())
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Radio::make('for')
                            ->label('For')
                            ->options(['lead' => __('A lead'), 'client' => __('A client')])
                            ->default(fn () => request()->integer('client') ? 'client' : 'lead')
                            ->inline()
                            ->required()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $set($get('for') === 'lead' ? 'client_id' : 'lead_id', null);
                                OrderForm::repriceItems($get, $set);
                            })
                            ->columnSpanFull(),
                        Select::make('lead_id')
                            ->label('Lead')
                            ->options(fn (?Quote $record) => Lead::query()->open()->whereNull('client_id')
                                ->when($record?->lead_id, fn ($query, $id) => $query->orWhere('id', $id))
                                ->orderBy('first_name')->get()
                                ->mapWithKeys(fn (Lead $lead) => [$lead->id => trim($lead->displayName().($lead->email ? " ({$lead->email})" : ''))]))
                            ->default(fn () => request()->integer('lead') ?: null)
                            ->searchable()
                            ->required(fn (Get $get) => $get('for') === 'lead')
                            ->visible(fn (Get $get) => $get('for') === 'lead')
                            ->helperText(__('Accepting it makes the lead a client, with the client area invitation.'))
                            ->live(),
                        Select::make('client_id')
                            ->label('Client')
                            ->options(fn () => Client::query()->orderBy('last_name')->get()->mapWithKeys(fn (Client $c) => [$c->id => $c->displayName()]))
                            ->default(fn () => request()->integer('client') ?: null)
                            ->searchable()
                            ->required(fn (Get $get) => $get('for') === 'client')
                            ->visible(fn (Get $get) => $get('for') === 'client')
                            ->live()
                            // Prices follow the client's currency.
                            ->afterStateUpdated(fn (Get $get, Set $set) => OrderForm::repriceItems($get, $set)),
                        DatePicker::make('valid_until')
                            ->native(false)
                            ->displayFormat(AppServiceProvider::dateFormat())
                            ->default(fn () => today()->addDays(Quotes::validDays()))
                            ->required(),
                    ]),

                AiTextActions::on(Textarea::make('intro')
                    ->label('Text above the lines')
                    ->rows(2)
                    ->maxLength(5000)
                    ->columnSpanFull()),

                OrderForm::itemsRepeater(forQuote: true)
                    ->helperText(__('The prices come from the catalogue; change them for a discount. The service keeps the price on its renewals.')),

                Section::make(__('One-off lines'))
                    ->description(__('Work done once (moving a site, setting up email): billed with the first payment only, never renewed.'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('extras')
                            ->hiddenLabel()
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add one-off line'))
                            ->live()
                            ->schema([
                                TextInput::make('description')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                TextInput::make('amount_cents')
                                    ->label('Amount')
                                    ->prefix(fn (Get $get) => self::currency($get('../../client_id')))
                                    ->required()
                                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null),
                            ]),
                    ]),

                Text::make(fn (Get $get) => __('First payment total: :amount', ['amount' => Tax::describe(
                    Tax::totals(self::taxClient($get('client_id'), $get('lead_id')), [...OrderForm::amounts($get('items') ?? []), ...self::extraAmounts($get('extras') ?? [])]),
                    self::currency($get('client_id')),
                )])),

                Textarea::make('footer')
                    ->label('Text below the lines')
                    ->placeholder(__('Terms, how to pay, what is not included…'))
                    ->default(fn () => config('gaiacp.sales.quote_footer'))
                    ->rows(3)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Notes for the admins')
                    ->rows(2)
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ]);
    }

    private static function currency(mixed $clientId): string
    {
        return Currencies::forClient($clientId ? Client::find($clientId) : null);
    }

    /** Who the tax rule is for: the client, or one made up from the lead's country. */
    private static function taxClient(mixed $clientId, mixed $leadId): ?Client
    {
        if ($clientId) {
            return Client::find($clientId);
        }

        $lead = $leadId ? Lead::find($leadId) : null;

        return $lead ? new Client(['country' => $lead->country]) : null;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $extras
     * @return list<int>
     */
    private static function extraAmounts(array $extras): array
    {
        return collect($extras)->values()
            ->map(fn (array $extra) => filled($extra['amount_cents'] ?? null) && preg_match('/^\d+([.,]\d{1,2})?$/', (string) $extra['amount_cents']) ? Money::toCents($extra['amount_cents']) : 0)
            ->all();
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Quote|null $record */
        return $record ? "{$record->number} · {$record->recipientName()}" : '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'lead']))
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient')
                    ->label('For')
                    ->state(fn (Quote $record) => $record->recipientName())
                    ->description(fn (Quote $record) => $record->client_id ? null : __('Lead')),
                TextColumn::make('total')
                    ->label('First payment')
                    ->state(fn (Quote $record) => $record->money($record->totals()['total'])),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (Quote $record) => $record->status === QuoteStatus::Declined ? $record->decline_reason : null),
                TextColumn::make('valid_until')
                    ->date(AppServiceProvider::dateFormat())
                    ->color(fn (Quote $record) => $record->status->isOpen() && $record->valid_until->lt(today()) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->date(AppServiceProvider::dateFormat())
                    ->placeholder('–')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label(fn (Quote $record) => $record->status->isOpen() ? __('Edit') : __('View')),
            ])
            ->emptyStateHeading(__('No quotes yet'))
            ->emptyStateDescription(__('Write a quote for a lead or a client: once they accept it on its page, it becomes an order.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }

    /**
     * The quote's attributes as the form shows them: prices as amounts, a domain's term in years, and who it is for.
     *
     * @return array<string, mixed>
     */
    public static function formData(Quote $quote): array
    {
        $decimal = fn ($cents) => $cents === null || $cents === '' ? null : Money::toDecimal((int) $cents);

        return [
            ...$quote->attributesToArray(),
            'for' => $quote->client_id ? 'client' : 'lead',
            'valid_until' => $quote->valid_until->toDateString(),
            'items' => collect($quote->items ?? [])->map(fn (array $item) => [
                ...$item,
                'price_cents' => $decimal($item['price_cents'] ?? null),
                'first_payment_cents' => $decimal($item['first_payment_cents'] ?? null),
                'years' => ($item['type'] ?? null) === 'domain' ? max(1, intdiv(BillingCycle::tryFrom((string) ($item['billing_cycle'] ?? ''))?->months() ?? 12, 12)) : null,
            ])->all(),
            'extras' => collect($quote->extras ?? [])->map(fn (array $extra) => [
                'description' => $extra['description'],
                'amount_cents' => $decimal($extra['amount_cents']),
            ])->all(),
        ];
    }
}
