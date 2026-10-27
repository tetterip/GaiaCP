<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Actions\CertificateLifecycle;
use App\Actions\ServiceLifecycle;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Certificates\CertificateEntries;
use App\Filament\Forms\NameserversInput;
use App\Filament\Forms\PackageSelect;
use App\Filament\Forms\PasswordInput;
use App\Filament\Forms\ProductOptionFields;
use App\Livewire\HostingUsage;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\UsernameGenerator;
use App\Rules\ValidDomain;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\ProductOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        $isHosting = fn (Get $get) => self::type($get) === ServiceType::Hosting;

        return $schema
            ->components([
                Section::make(__('Service'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('client_id')
                            ->relationship('client', 'last_name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->displayName())
                            ->searchable(['first_name', 'last_name', 'company', 'email'])
                            ->preload()
                            ->required()
                            ->default(fn () => request()->integer('client') ?: null)
                            // Once set up, moving it to another client also takes it off the old client's notices: Move to another client.
                            ->disabled(fn (?Service $record) => self::typeLocked($record))
                            ->helperText(fn (?Service $record) => self::typeLocked($record) ? __('Fixed once the service has been set up. Use Move to another client.') : null)
                            ->live()
                            // A new service is billed in its client's currency.
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                                if ($operation === 'create') {
                                    $set('currency', Currencies::forClient($state ? Client::find($state) : null));
                                    self::priceFromProduct($get, $set, $get('billing_cycle'));
                                }
                            }),
                        // Type first: the product list shows only products of that type.
                        Select::make('type')
                            ->options(ServiceType::class)
                            ->default(fn () => ServiceType::tryFrom((string) request()->query('type')) ?? ServiceType::Hosting)
                            ->required()
                            // Once set up, a domain can't become hosting: its account, registrar and payments belong to its type.
                            ->disabled(fn (?Service $record) => self::typeLocked($record))
                            ->helperText(fn (?Service $record) => self::typeLocked($record) ? __('Fixed once the service has been set up. Cancel it and order a new one to change the type.') : null)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                // A product of another type no longer fits.
                                if (($product = Product::find($get('product_id'))) && $product->type !== self::type($get)) {
                                    $set('product_id', null);
                                }
                            }),
                        Select::make('product_id')
                            ->label('Product')
                            // Retired products are not offered, but a service keeps showing its own.
                            ->relationship('product', 'name', fn (Builder $query, ?Service $record, Get $get) => $query
                                ->where(fn (Builder $q) => $q->selectable()->orWhere('id', $record?->product_id))
                                ->when(self::type($get), fn (Builder $q, ServiceType $type) => $q->where('type', $type))
                                ->orderBy('name'))
                            ->placeholder(__('None (custom)'))
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, ?string $state, string $operation) => $operation === 'create' ? self::fillFromProduct($get, $set, $state) : null)
                            ->helperText(__('Fills in type, label, price, cycle, server and package. You can still change them.')),
                        TextInput::make('label')
                            // A domain is called by its name: the model sets it from the domain.
                            ->required(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                            ->visible(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                            ->dehydratedWhenHidden()
                            ->dehydrateStateUsing(fn (Get $get, ?string $state) => self::type($get) === ServiceType::Domain ? null : $state)
                            ->maxLength(255)
                            ->helperText(__('Shown to the client in reminders, e.g. "Web hosting — Basic".')),
                        TextInput::make('domain')
                            ->required(fn (Get $get) => (bool) self::type($get)?->needsDomain())
                            ->maxLength(255)
                            ->rule(new ValidDomain)
                            ->helperText(fn (Get $get) => self::type($get) === ServiceType::Maintenance ? __('The website this maintenance covers (optional).') : null),
                        Select::make('status')
                            ->options(ServiceStatus::class)
                            ->default(ServiceStatus::Active)
                            ->required()
                            ->live(),
                        TextInput::make('suspension_reason')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => self::enum(ServiceStatus::class, $get('status')) === ServiceStatus::Suspended)
                            ->helperText(__('"overdue" means suspended for non-payment: a payment unsuspends it automatically.')),
                        Toggle::make('client_visible')
                            ->label('Show in client area')
                            ->default(true)
                            ->helperText(__('Off: the client never sees this service in the client area.')),
                        Text::make(fn (?Service $record) => __('Add-on of :service: it keeps its cycle and due date and follows its status.', ['service' => $record?->parent?->label]))
                            ->visible(fn (?Service $record) => $record?->parent !== null)
                            ->columnSpanFull(),
                        Text::make(__('Changing the status here does not touch the server. Use the Account menu above to create, suspend, unsuspend or terminate on the panel.'))
                            ->visible(fn (Get $get, string $operation) => $operation === 'edit' && $isHosting($get))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Hosting account'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible($isHosting)
                    ->schema([
                        Select::make('server_id')
                            ->relationship('server', 'name', fn (Builder $query) => $query->withAccountsCount())
                            ->getOptionLabelFromRecordUsing(fn (Server $record) => $record->optionLabel())
                            ->preload()
                            ->live()
                            ->required($isHosting),
                        PackageSelect::make(fn (Get $get) => $get('server_id'))
                            ->helperText(__('Package / service plan name on the panel.')),
                        TextInput::make('username')
                            ->maxLength(fn (Get $get) => UsernameGenerator::maxLength(Server::find($get('server_id'))?->type))
                            ->regex('/^[a-z][a-z0-9]*$/')
                            ->helperText(__('Leave empty for a new account: it is generated from the domain.')),
                        PasswordInput::make()
                            // The model hides it from arrays, so load it explicitly; a blank field keeps the stored one.
                            ->formatStateUsing(fn (?Service $record) => $record?->password)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(__('Leave empty for a new account: a strong one is generated. Stored encrypted. Editing it here does not change it on the server: use Account → Change password.')),
                    ]),

                Section::make(__('Usage'))
                    ->description(__('Read from the panel; kept for :minutes minutes.', ['minutes' => ServiceLifecycle::USAGE_MINUTES]))
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (?Service $record, string $operation) => $operation === 'edit' && HostingUsage::hasUsage($record))
                    ->schema([
                        Livewire::make(HostingUsage::class, fn (Service $record) => ['service' => $record])
                            ->key('hostingUsage')
                            ->lazy(),
                    ]),

                Section::make(__('Domain registration'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->relationship('domainDetails')
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain)
                    ->schema([
                        // Past Pending, Register and Transfer in both mean "at the registrar": only what the choices say changes.
                        Select::make('registration_type')
                            ->label('Registration')
                            ->options(fn ($record) => self::pastPending($record) ? [
                                DomainRegistrationType::Register->value => __('At the registrar (synced and renewed there)'),
                                DomainRegistrationType::Transfer->value => DomainRegistrationType::Transfer->getLabel(),
                                DomainRegistrationType::External->value => DomainRegistrationType::External->getLabel(),
                            ] : DomainRegistrationType::class)
                            ->default(DomainRegistrationType::Register)
                            ->required()
                            ->live()
                            ->helperText(fn ($record) => self::pastPending($record)
                                ? __('Track only: GaiaCP never calls a registrar for it (no sync, no renewal on payment). A domain already in your registrar account is "At the registrar".')
                                : __('What "Register domain" in the Registrar menu does while the domain is pending.')),
                        Select::make('registrar_id')
                            ->label('Registrar')
                            ->relationship('registrar', 'name', fn (Builder $query) => $query->where('active', true)->orderBy('name'))
                            ->placeholder(__('The TLD\'s preferred registrar'))
                            // A tracked domain is at no registrar of ours; a chosen one is kept for when that changes.
                            ->hidden(fn (Get $get) => self::enum(DomainRegistrationType::class, $get('registration_type')) === DomainRegistrationType::External),
                        TextInput::make('epp_code')
                            ->label('EPP code')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            // The model hides it from arrays, so load it explicitly; a blank field keeps the stored one.
                            ->formatStateUsing(fn ($record) => $record instanceof Domain ? $record->epp_code : null)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->visible(fn (Get $get) => self::enum(DomainRegistrationType::class, $get('registration_type')) === DomainRegistrationType::Transfer),
                        // Saving the form never calls the registrar, so once a domain is at one its nameservers
                        // change only through Registrar → Change nameservers; here they are read-only.
                        NameserversInput::make()
                            ->disabled(fn (Get $get, $record) => self::nameserversAtRegistrar($get, $record))
                            ->helperText(fn (Get $get, $record) => self::nameserversAtRegistrar($get, $record)
                                ? __('To change them at the registrar use Registrar → Change nameservers. "Sync" reads the current ones.')
                                : __('Empty = the default nameservers from Settings when registering. "Sync" reads the current ones.')),
                        DatePicker::make('expires_at')
                            ->label('Expires at the registrar')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Read from the registrar by "Sync". Compare it with the next due date.')),
                    ]),

                Section::make(__('SSL certificate'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Ssl)
                    ->schema([
                        Select::make('package')
                            ->label('Certificate')
                            ->options(fn (?Service $record) => self::certificateOptions($record))
                            ->searchable()
                            ->required(fn () => app(CertificateLifecycle::class)->productOptions() !== [])
                            ->helperText(fn () => app(CertificateLifecycle::class)->productOptions() === []
                                ? __('Switch on an SSL provider under Settings → Plugins (GoGetSSL) and fill in its login.')
                                : __('Ordered from the provider once the service is set up (on payment, or SSL → Order the certificate).'))
                            ->columnSpanFull(),
                        Group::make(CertificateEntries::make())
                            ->columns(3)
                            ->columnSpanFull()
                            ->visible(fn (string $operation) => $operation === 'edit'),
                    ]),

                Section::make(__('Billing'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('price_cents')
                            ->label('Price per cycle')
                            ->prefix(fn (Get $get) => $get('currency') ?: Currencies::base())
                            ->required()
                            ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                            ->formatStateUsing(fn ($state) => $state === null ? null : Money::toDecimal((int) $state))
                            ->dehydrateStateUsing(fn ($state) => Money::toCents($state)),
                        // Until it is first paid: what its first payment notice asks for (EditService keeps it in step with the price).
                        TextInput::make('first_payment_cents')
                            ->label('First payment')
                            ->prefix(fn (Get $get) => $get('currency') ?: Currencies::base())
                            ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
                            ->formatStateUsing(fn ($state) => $state === null ? null : Money::toDecimal((int) $state))
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null)
                            ->helperText(fn (Get $get) => self::type($get) === ServiceType::Domain
                                ? __('Registration or transfer price. Empty = the renewal price.')
                                : __('Price plus setup fee. Empty = the price.'))
                            ->visible(fn (string $operation, ?Service $record) => $operation === 'edit' && $record !== null && ! $record->payments()->exists()),
                        Select::make('billing_cycle')
                            // One time only for other services (paid once, then Completed)
                            ->options(fn (Get $get) => BillingCycle::optionsFor(self::type($get)))
                            ->enum(BillingCycle::class)
                            ->default(BillingCycle::Annually)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, $state, string $operation) => $operation === 'create' ? self::priceFromProduct($get, $set, $state) : null),
                        DatePicker::make('start_date')
                            ->required()
                            ->default(now())
                            ->helperText(__('Its day of month anchors renewals, so month-end dates don\'t drift.')),
                        DatePicker::make('next_due_date')
                            ->required()
                            ->default(now())
                            ->helperText(__('The date the next payment is due. For a new, unpaid service this is the start date; recording a payment moves it forward one cycle.')),
                        Hidden::make('currency')
                            ->default(fn () => Currencies::forClient(Client::find(request()->integer('client')))),
                        // When a pending service is set up, from its product (AutoSetup).
                        Hidden::make('auto_setup')
                            ->default(AutoSetup::OnAccept->value),
                        // What the picked options add to the price, so a new pick changes it by the difference.
                        Hidden::make('options_total')
                            ->default(0)
                            ->dehydrated(false),
                    ]),

                Section::make(__('Product options'))
                    ->description(__('A different pick changes the price per cycle by the difference. Nothing changes on the server.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => ProductOptions::for(self::product($get)) !== [])
                    ->schema(fn (Get $get) => ProductOptionFields::make(
                        self::product($get),
                        fn (Get $get) => self::enum(BillingCycle::class, $get('billing_cycle')),
                        fn (Get $get) => $get('currency'),
                        fn (Get $get, Set $set, ?Service $record = null) => self::optionsChanged($get, $set, $record),
                    )),

                Section::make(__('Automation'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        DatePicker::make('override_suspend_until')
                            ->label('Do not suspend until')
                            ->visible($isHosting)
                            ->helperText(__('Overrides "Suspend hosting after" in Settings for this account until this date.')),
                        // The reason sits right under its toggle.
                        Group::make([
                            Toggle::make('auto_terminate')
                                ->label('Auto-terminate at the end of the cycle')
                                ->live()
                                ->visible(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                                ->helperText(__('Terminated on its next due date (for hosting, the account is deleted from the server). No more payment notices are created for it.')),
                            // The same switch the other way round, named as in WHMCS (Service::autoRenew()).
                            Toggle::make('auto_renew')
                                ->label('Auto renew')
                                ->live()
                                ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain)
                                ->formatStateUsing(fn (?Service $record) => $record ? ! $record->auto_terminate : (bool) config('gaiacp.domains.auto_renew', true))
                                ->helperText(__('Off: no more payment notices or renewal emails, and the domain ends (Terminated) on its next due date, so it expires at the registrar.')),
                            TextInput::make('auto_terminate_reason')
                                ->label('Reason')
                                ->maxLength(255)
                                ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain ? ! $get('auto_renew') : (bool) $get('auto_terminate')),
                        ]),
                    ]),

                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    private static function fillFromProduct(Get $get, Set $set, ?string $productId): void
    {
        $product = $productId ? Product::find($productId) : null;

        // Back to "None (custom)": undo what the product filled in, so its type's sections go away too.
        if (! $product) {
            $set('type', ServiceType::tryFrom((string) request()->query('type')) ?? ServiceType::Hosting);
            $set('label', null);
            $set('price_cents', null);
            $set('server_id', null);
            $set('package', null);
            $set('auto_setup', AutoSetup::OnAccept->value);
            $set('client_visible', true);
            $set('options', []);
            $set('options_total', 0);

            return;
        }

        $set('type', $product->type);
        $set('label', $product->name);
        $cycle = $product->defaultCycle();
        $set('options', ProductOptions::defaults(ProductOptions::for($product)));
        $set('price_cents', null);
        $set('options_total', 0);

        if ($cycle) {
            self::priceWithOptions($get, $set, $product, $cycle, $product->priceFor($cycle, $get('currency')));
        }

        $set('auto_setup', ($product->auto_setup ?? AutoSetup::OnAccept)->value);
        $set('client_visible', $product->client_visible);
        $set('billing_cycle', $cycle);
        $set('server_id', $product->pickServer()?->id);
        $set('package', $product->package);
    }

    /** @return array<string, string> the SSL providers' certificates, and the service's own even if no longer sold */
    private static function certificateOptions(?Service $record): array
    {
        $options = app(CertificateLifecycle::class)->productOptions();

        if (filled($record?->package) && ! isset($options[$record->package])) {
            $options[$record->package] = $record->package;
        }

        return $options;
    }

    /** The product's price for the picked cycle, when the product offers it. */
    private static function priceFromProduct(Get $get, Set $set, mixed $cycle): void
    {
        $product = $get('product_id') ? Product::find($get('product_id')) : null;
        $cycle = self::enum(BillingCycle::class, $cycle);
        $price = $product && $cycle ? $product->priceFor($cycle, $get('currency')) : null;

        if ($price !== null) {
            self::priceWithOptions($get, $set, $product, $cycle, $price);
        }
    }

    /** The product's price plus what its picked options add. */
    private static function priceWithOptions(Get $get, Set $set, Product $product, BillingCycle $cycle, int $price): void
    {
        $options = ProductOptions::total(ProductOptions::select($product, (array) ($get('options') ?? []), $cycle, $get('currency')));
        $set('price_cents', Money::toDecimal($price + $options));
        $set('options_total', $options);
    }

    /** Another pick: the price changes by what the options add now against before (picks kept as they were keep their price then). */
    private static function optionsChanged(Get $get, Set $set, ?Service $record): void
    {
        $cycle = self::enum(BillingCycle::class, $get('billing_cycle'));

        if (! $cycle) {
            return;
        }

        $options = ProductOptions::total(ProductOptions::select(
            self::product($get),
            (array) ($get('options') ?? []),
            $cycle,
            $get('currency'),
            $record?->exists && $record->billing_cycle === $cycle ? $record->options : null,
        ));

        try {
            $price = Money::toCents($get('price_cents') ?: 0);
        } catch (\InvalidArgumentException) {
            return;
        }

        $set('price_cents', Money::toDecimal(max(0, $price - (int) $get('options_total') + $options)));
        $set('options_total', $options);
    }

    private static function product(Get $get): ?Product
    {
        return $get('product_id') ? Product::find($get('product_id')) : null;
    }

    /** A domain's details whose service is past Pending (registered, transferred or marked active). */
    private static function pastPending(mixed $record): bool
    {
        return $record instanceof Domain && $record->service !== null && $record->service->status !== ServiceStatus::Pending;
    }

    /** A domain past Pending that is at a registrar (not tracked only): its nameservers live there. */
    private static function nameserversAtRegistrar(Get $get, mixed $record): bool
    {
        return $record instanceof Domain
            && $record->service?->status !== ServiceStatus::Pending
            && self::enum(DomainRegistrationType::class, $get('registration_type')) !== DomainRegistrationType::External;
    }

    /** The type can change only while the service is new or still pending. */
    private static function typeLocked(?Service $record): bool
    {
        return $record?->exists && $record->status !== ServiceStatus::Pending;
    }

    private static function type(Get $get): ?ServiceType
    {
        return self::enum(ServiceType::class, $get('type'));
    }

    /**
     * Form state holds either the enum or its raw value depending on how it was set.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $state): ?\BackedEnum
    {
        return $state instanceof $enum ? $state : ($state === null || $state === '' ? null : $enum::tryFrom($state));
    }
}
