<?php

namespace App\Filament\Resources\TicketDepartments;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\TicketDepartments\Pages\ManageTicketDepartments;
use App\Models\TicketDepartment;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Ticket departments: where tickets go (e.g. Technical support, Sales, Billing). Deleting one leaves its tickets without one. */
class TicketDepartmentResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = TicketDepartment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 51;

    protected static ?string $navigationLabel = 'Ticket departments';

    protected static ?string $modelLabel = 'department';

    protected static ?string $pluralModelLabel = 'Ticket departments';

    protected static ?string $slug = 'ticket-departments';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function settingsDescription(): string
    {
        return __('Where tickets go, such as Technical support or Billing, and which ones clients can choose.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('email')
                    ->email()
                    ->maxLength(190)
                    ->helperText(__('Optional: its own address, for receiving tickets by email later.')),
                TextInput::make('sort')
                    ->label('Position')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('client_visible')
                    ->label('Clients can choose it')
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email')->placeholder('–'),
                IconColumn::make('client_visible')->label('Clients can choose it')->boolean(),
                TextColumn::make('tickets_count')->label('Tickets')->counts('tickets'),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No departments yet'))
            ->emptyStateDescription(__('Without departments, tickets go to everyone who opens tickets.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTicketDepartments::route('/'),
        ];
    }
}
