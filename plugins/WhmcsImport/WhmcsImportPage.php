<?php

namespace Plugins\WhmcsImport;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Plugins\WhmcsImport\WhmcsImport as Importer;
use Throwable;
use UnitEnum;

/**
 * Settings → Import from WHMCS: connects to a WHMCS database (read-only), shows what a dry run finds, then
 * imports (Plugins\WhmcsImport\WhmcsImport). Nothing typed here is stored; defaults come from WHMCS_DB_* in .env.
 */
class WhmcsImportPage extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 94;

    protected static ?string $title = 'Import from WHMCS';

    protected static ?string $slug = 'whmcs-import';

    /** Hidden while the WHMCS import plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('whmcs-import') && static::canUseAdminArea() && parent::canAccess();
    }

    public static function settingsDescription(): string
    {
        return __('Copy clients, products, services, domains and unpaid invoices from a WHMCS database.');
    }

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The last run's WhmcsImportReport::toArray(), null before the first. */
    public ?array $report = null;

    public function mount(): void
    {
        $config = config('database.connections.'.WhmcsConnection::NAME);

        $this->form->fill([
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'database' => $config['database'] ?? null,
            'username' => $config['username'] ?? null,
            'api_url' => $config['api_url'] ?? null,
            'api_identifier' => $config['api_identifier'] ?? null,
            'parts' => array_keys(Importer::PARTS),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('WHMCS database'))
                    ->description(__('GaiaCP only reads it. A read-only MySQL user is enough. To import from a backup (.sql), restore it into an empty database first (Plesk: Databases → Import dump) and connect to that.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('host')->label('Host')->required()->maxLength(255),
                        TextInput::make('port')->label('Port')->integer()->minValue(1)->maxValue(65535)->placeholder('3306'),
                        TextInput::make('database')->label('Database')->required()->maxLength(255),
                        TextInput::make('username')->label('Username')->autocomplete('off')->required()->maxLength(255),
                        TextInput::make('password')->label('Password')->password()->revealable()->autocomplete('new-password')->maxLength(255)
                            ->placeholder(fn () => filled(config('database.connections.'.WhmcsConnection::NAME.'.password')) ? __('From .env (leave blank to use it)') : null),
                        TextInput::make('hash')->label('Encryption hash')->password()->revealable()->autocomplete('new-password')->maxLength(255)
                            ->placeholder(fn () => filled(config('database.connections.'.WhmcsConnection::NAME.'.encryption_hash')) ? __('From .env (leave blank to use it)') : null)
                            ->helperText(__('$cc_encryption_hash in WHMCS\'s configuration.php. Needed for hosting account and Plesk server passwords.')),
                        Section::make(__('WHMCS API (optional)'))
                            ->description(__('For the passwords GaiaCP can\'t decrypt itself, it asks WHMCS (DecryptPassword; nothing is changed there). In WHMCS, create a credential under API Credentials with a role that allows DecryptPassword (under System API Actions), and add this server\'s IP, IPv6 too if it has one, under General Settings → Security → API IP Access Restriction.'))
                            ->columns(3)
                            ->collapsible()
                            ->collapsed(fn () => blank(config('database.connections.'.WhmcsConnection::NAME.'.api_url')))
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('api_url')->label('WHMCS URL')->url()->maxLength(255)->placeholder('https://my.example.com/whmcs'),
                                TextInput::make('api_identifier')->label('API identifier')->maxLength(255),
                                TextInput::make('api_secret')->label('API secret')->password()->revealable()->autocomplete('new-password')->maxLength(255)
                                    ->placeholder(fn () => filled(config('database.connections.'.WhmcsConnection::NAME.'.api_secret')) ? __('From .env (leave blank to use it)') : null),
                            ]),
                        CheckboxList::make('parts')
                            ->label('Import')
                            ->options(fn () => array_map(fn (string $label) => __($label), Importer::PARTS))
                            ->helperText(__('Something left out is not created, but what is already in GaiaCP (same email, hostname, product name or domain) is still used by the rest. Run it again later to import the rest; what was imported before is skipped.'))
                            ->required()
                            ->columns(4)
                            ->bulkToggleable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->footer([
                    Actions::make([
                        Action::make('dryRun')
                            ->label('Dry run')
                            ->icon(Heroicon::OutlinedMagnifyingGlass)
                            ->color('gray')
                            ->action(fn () => $this->runImport(dryRun: true)),
                        Action::make('import')
                            ->label('Import')
                            ->icon(Heroicon::OutlinedArrowDownOnSquareStack)
                            ->requiresConfirmation()
                            ->modalDescription(__('Copies everything the dry run lists into GaiaCP. Records imported before are skipped, so it is safe to run again.'))
                            ->action(fn () => $this->runImport(dryRun: false)),
                    ]),
                ]),
            Section::make(fn () => $this->report['dry_run'] ?? true ? __('Dry run: nothing was saved') : __('Imported'))
                ->visible(fn () => $this->report !== null)
                ->schema(fn () => [
                    EmbeddedTable::make(),
                    $this->report['problems'] === []
                        ? Callout::make(__('No problems found'))->success()
                        : Callout::make(__(':count problem(s)', ['count' => count($this->report['problems'], COUNT_RECURSIVE) - count($this->report['problems'])]))
                            ->description(__('Check these after importing; the rest is imported as described.'))
                            ->warning(),
                    ...collect($this->report['problems'])->map(fn (array $problems, string $group) => Section::make(__(WhmcsImportReport::GROUPS[$group] ?? $group).' ('.count($problems).')')
                        ->key("problems-{$group}")
                        ->collapsible()
                        ->collapsed(count($problems) > 10)
                        // Filament's text is inline-block, which puts a long item's bullet beside its last line; inline keeps it on the first.
                        ->schema([UnorderedList::make(array_map(fn (string $problem) => Text::make($problem)->extraAttributes(['style' => 'display: inline'], merge: true), $problems))]))
                        ->values()
                        ->all(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->report === null ? [] : collect($this->report['counts'])
                ->map(fn (array $counts, string $entity) => ['label' => __(WhmcsImportReport::ENTITIES[$entity]), ...$counts])
                ->all())
            ->columns([
                TextColumn::make('label')->label('')->weight('medium'),
                TextColumn::make('created')->label('New'),
                TextColumn::make('matched')->label('Already here (linked)'),
                TextColumn::make('existing')->label('Imported before'),
                TextColumn::make('skipped')->label('Skipped'),
            ])
            ->paginated(false);
    }

    public function runImport(bool $dryRun): void
    {
        $data = $this->form->getState();

        WhmcsConnection::configure([
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => $data['password'] ?? null,
        ]);

        try {
            $report = (new Importer(WhmcsConnection::NAME, ($data['hash'] ?? null) ?: config('database.connections.'.WhmcsConnection::NAME.'.encryption_hash'), WhmcsApi::make(
                $data['api_url'] ?? null,
                $data['api_identifier'] ?? null,
                ($data['api_secret'] ?? null) ?: config('database.connections.'.WhmcsConnection::NAME.'.api_secret'),
            )))
                ->run($dryRun, $data['parts']);
        } catch (Throwable $e) {
            Notification::make()->danger()->title($dryRun ? __('Dry run failed') : __('Import failed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->report = $report->toArray();
        $this->flushCachedTableRecords();

        Notification::make()->success()
            ->title($dryRun ? __('Dry run finished') : __('Import finished'))
            ->body($dryRun ? __('Would import :summary.', ['summary' => $report->summary()]) : __('Imported :summary.', ['summary' => $report->summary()]))
            ->send();
    }
}
