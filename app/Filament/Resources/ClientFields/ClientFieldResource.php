<?php

namespace App\Filament\Resources\ClientFields;

use App\Enums\AdminArea;
use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ClientFields\Pages\ManageClientFields;
use App\Models\ClientField;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings → Client fields: the admin's own fields on the client form, sign up and the client's profile (e.g. an ID
 * number, a contact person), so a country's details need no plugin. Deleting one keeps its values on the clients.
 */
class ClientFieldResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ClientField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'General';

    protected static ?int $navigationSort = 13;

    protected static ?string $navigationLabel = 'Client fields';

    protected static ?string $modelLabel = 'client field';

    protected static ?string $pluralModelLabel = 'Client fields';

    protected static ?string $slug = 'client-fields';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Your own fields on the client form, sign up and the client\'s profile, and which ones payment notices show.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fieldset::make(__('Label'))
                    ->columnSpanFull()
                    ->columns(count(Locales::available()) > 1 ? 2 : 1)
                    ->schema(collect(Locales::available())->map(fn (string $name, string $code) => TextInput::make("labels.{$code}")
                        ->label($name)
                        ->translateLabel(false)
                        ->required($code === Locales::default())
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set, ?ClientField $record) use ($code) {
                            if ($record === null && $code === Locales::default() && blank($get('key'))) {
                                $set('key', Str::slug((string) $state, '_'));
                            }
                        }))->values()->all()),
                TextInput::make('key')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?ClientField $record) => $record !== null)
                    ->helperText(__('Lower case letters, digits and _; it can\'t change later, as the values are kept under it.')),
                Select::make('type')
                    ->options(ClientFieldType::class)
                    ->default(ClientFieldType::Text)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                TagsInput::make('options')
                    ->label('Choices')
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => self::type($get('type')) === ClientFieldType::Select)
                    ->required(fn (Get $get) => self::type($get('type')) === ClientFieldType::Select)
                    ->helperText(__('Type each choice and press Enter.')),
                Select::make('client_access')
                    ->label('In the client area')
                    ->options(ClientFieldAccess::class)
                    ->default(ClientFieldAccess::Editable)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('required')
                    ->helperText(__('Must be filled in on the client form, and at sign up when clients fill it in.')),
                Toggle::make('on_notice')
                    ->label('Show on payment notices')
                    ->helperText(__('Under the client\'s details, as they are on the day the notice is issued.')),
            ]);
    }

    private static function type(mixed $state): ?ClientFieldType
    {
        return $state instanceof ClientFieldType ? $state : ClientFieldType::tryFrom((string) $state);
    }

    /** @param  array<string, mixed>  $data */
    public static function forStorage(array $data): array
    {
        if (self::type($data['type'] ?? null) !== ClientFieldType::Select) {
            $data['options'] = null;
        }
        $data['labels'] = array_filter((array) ($data['labels'] ?? []), 'filled');

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('labels')
                    ->label('Label')
                    ->state(fn (ClientField $record) => $record->label()),
                TextColumn::make('key')->color('gray'),
                TextColumn::make('type'),
                TextColumn::make('client_access')
                    ->label('In the client area')
                    ->formatStateUsing(fn (ClientFieldAccess $state) => __(match ($state) {
                        ClientFieldAccess::Hidden => 'Hidden',
                        ClientFieldAccess::Shown => 'Shown',
                        ClientFieldAccess::Editable => 'Editable',
                    })),
                IconColumn::make('required')->boolean(),
                IconColumn::make('on_notice')->label('On payment notices')->boolean(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::forStorage($data)),
                DeleteAction::make()
                    ->modalDescription(__('The values clients have in it are kept, but no longer shown anywhere. Payment notices already issued keep showing it.')),
            ])
            ->emptyStateHeading(__('No client fields yet'))
            ->emptyStateDescription(__('Add fields your clients need that GaiaCP does not have, such as an ID card number or a contact person.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientFields::route('/'),
        ];
    }
}
