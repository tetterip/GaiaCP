<?php

namespace App\Filament\Actions;

use App\Actions\ImportTldPrices;
use App\Models\Registrar;
use App\Models\Tld;
use App\Provisioning\ProvisioningException;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Domain pricing → Import from registrar (ImportTldPrices): pick a registrar and the markup, then the TLDs
 * to import from the list it gives, each showing its cost, the price it would get and the price it has now.
 */
class ImportTldPricesAction
{
    public static function make(): Action
    {
        return Action::make('importTldPrices')
            ->label('Import from registrar')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn () => app(ImportTldPrices::class)->registrars()->isNotEmpty())
            ->modalHeading(__('Import TLDs and prices from a registrar'))
            ->modalWidth('3xl')
            ->steps([
                Step::make(__('Registrar and markup'))
                    ->schema([
                        Select::make('registrar_id')
                            ->label('Registrar')
                            ->options(fn () => app(ImportTldPrices::class)->registrars()->pluck('name', 'id'))
                            ->default(fn () => app(ImportTldPrices::class)->registrars()->first()?->id)
                            ->required()
                            ->live(),
                        TagsInput::make('only')
                            ->label('TLDs to read')
                            ->placeholder('.com')
                            ->helperText(fn (Get $get) => self::listsAll($get('registrar_id'))
                                ? __('Empty: every TLD the registrar sells.')
                                : __('This registrar is asked about each TLD on its own (at most 50). Empty: the TLDs it is the preferred registrar of.')),
                        Grid::make(['default' => 1, 'sm' => 3])->schema([
                            TextInput::make('percent')
                                ->label('Markup')
                                ->numeric()
                                ->minValue(-100)
                                ->maxValue(1000)
                                ->default(20)
                                ->suffix('%')
                                ->live(onBlur: true),
                            TextInput::make('fixed')
                                ->label('Plus')
                                ->prefix(Currencies::base())
                                ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
                                ->default('0')
                                ->live(onBlur: true),
                            Select::make('rounding')
                                ->label('Round up')
                                ->options([
                                    'none' => __('To the cent'),
                                    'whole' => __('To a whole amount'),
                                    '99' => __('To .99'),
                                ])
                                ->default('none')
                                ->selectablePlaceholder(false)
                                ->live(),
                        ]),
                        Radio::make('prices')
                            ->label('TLDs already on the list')
                            ->options([
                                0 => __('Update the cost only'),
                                1 => __('Update the cost and set the price from it'),
                            ])
                            ->default(0)
                            ->helperText(__('New TLDs always get prices from the cost and the markup.')),
                        Toggle::make('preferred')
                            ->label('Make it the preferred registrar of TLDs already on the list')
                            ->helperText(__('New TLDs always get it. The daily cost update reads each TLD from its preferred registrar.')),
                        Toggle::make('active')
                            ->label('Offer new TLDs at once')
                            ->helperText(__('Off: new TLDs are added not offered, so you can check them first.')),
                    ])
                    ->afterValidation(function (Get $get, Set $set) {
                        $registrar = Registrar::query()->find($get('registrar_id'));

                        try {
                            $rows = $registrar ? app(ImportTldPrices::class)->cached($registrar, self::only($get('only'))) : [];
                        } catch (ProvisioningException $e) {
                            Notification::make()->danger()->title(__('Could not read the prices'))->body($e->getMessage())->persistent()->send();

                            throw new Halt;
                        }

                        if ($rows === []) {
                            Notification::make()->warning()->title(__('The registrar gave no TLD prices.'))->send();

                            throw new Halt;
                        }

                        // The TLDs already on the list are ticked; new ones are left for the admin to pick.
                        $set('tlds', Tld::query()->whereIn('tld', array_keys($rows))->pluck('tld')->all());
                    }),
                Step::make(__('TLDs'))
                    ->description(fn () => __('Register / renew / transfer in :currency, for the term the registrar prices. Ticked: the TLDs already on the list.', ['currency' => Currencies::base()]))
                    ->schema([
                        CheckboxList::make('tlds')
                            ->hiddenLabel()
                            ->options(fn (Get $get) => array_combine($keys = array_keys(self::rows($get)), $keys))
                            ->descriptions(fn (Get $get) => self::descriptions($get))
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(['default' => 1, 'sm' => 2])
                            ->required(),
                    ]),
            ])
            ->action(function (array $data) {
                $registrar = Registrar::query()->findOrFail($data['registrar_id']);
                $import = app(ImportTldPrices::class);
                $rows = array_values(array_intersect_key($import->cached($registrar, self::only($data['only'] ?? [])), array_flip($data['tlds'] ?? [])));
                $counts = $import->apply($registrar, $rows, self::options($data));

                Notification::make()->success()
                    ->title(__('Imported from :registrar', ['registrar' => $registrar->name]))
                    ->body(__(':created new TLDs, :updated updated.', $counts))
                    ->send();
            });
    }

    /** @return list<string>|null */
    private static function only(mixed $only): ?array
    {
        return is_array($only) && $only !== [] ? array_values(array_map('strval', $only)) : null;
    }

    private static function listsAll(mixed $registrarId): bool
    {
        $registrar = Registrar::query()->find($registrarId);

        return (bool) ($registrar ? app(ImportTldPrices::class)->pricer($registrar)?->listsAllTlds() : true);
    }

    /** @return array<string, array{tld: string, years: int, register: ?int, renew: ?int, transfer: ?int, redemption: ?int}> the reply cached by the first step */
    private static function rows(Get $get): array
    {
        $registrar = Registrar::query()->find($get('registrar_id'));

        if (! $registrar) {
            return [];
        }

        try {
            return app(ImportTldPrices::class)->cached($registrar, self::only($get('only')));
        } catch (ProvisioningException) {
            return [];
        }
    }

    /** @return array{percent: float, fixed_cents: int, rounding: string, prices: bool, preferred: bool, active: bool} */
    private static function options(array $data): array
    {
        try {
            $fixed = filled($data['fixed'] ?? null) ? Money::toCents((string) $data['fixed']) : 0;
        } catch (\InvalidArgumentException) {
            $fixed = 0;
        }

        return [
            'percent' => (float) ($data['percent'] ?? 0),
            'fixed_cents' => $fixed,
            'rounding' => (string) ($data['rounding'] ?? 'none'),
            'prices' => (bool) ($data['prices'] ?? false),
            'preferred' => (bool) ($data['preferred'] ?? false),
            'active' => (bool) ($data['active'] ?? false),
        ];
    }

    /**
     * "2 years · cost 12.00 / 14.00 / 10.00 → 14.40 / 16.80 / 12.00 (now 15.00 / 15.00 / 15.00)": register, renew and
     * transfer, for the term the registrar priced.
     *
     * @return array<string, string>
     */
    private static function descriptions(Get $get): array
    {
        $options = self::options($get());
        $existing = Tld::query()->whereIn('tld', array_keys($rows = self::rows($get)))->get()->keyBy('tld');
        $amounts = fn (array $cents) => implode(' / ', array_map(fn (?int $c) => $c === null ? '–' : Money::toDecimal($c), $cents));

        return collect($rows)->map(function (array $row) use ($options, $existing, $amounts) {
            $costs = array_map(fn (string $kind) => $row[$kind], ImportTldPrices::KINDS_SHOWN);
            $tld = $existing->get($row['tld']);
            $setsPrice = ! $tld || $options['prices'];
            $prices = array_map(fn (?int $cost) => $cost === null ? null : ImportTldPrices::sellingPrice($cost, $options['percent'], $options['fixed_cents'], $options['rounding']), $costs);

            return Tld::termLabel($row['years']).' · '.__('cost :amounts', ['amounts' => $amounts($costs)])
                .($setsPrice ? ' → '.$amounts($prices) : '')
                .($tld ? ' ('.__('now :amounts', ['amounts' => $amounts(array_map(fn (string $kind) => $tld->priceCents($kind, $row['years']), ImportTldPrices::KINDS_SHOWN))]).')' : ' · '.__('new'));
        })->all();
    }
}
