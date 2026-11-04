<?php

namespace App\Filament\Resources\Leads;

use App\Actions\Leads;
use App\Enums\AdminArea;
use App\Enums\LeadStatus;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\NotesRelationManager;
use App\Models\Lead;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Region;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Intl\Countries;
use UnitEnum;

/**
 * Clients → Leads (App\Actions\Leads): prospective clients, kept apart from the clients until they buy, with where
 * they came from, their notes and when to contact them next. Convert to client makes one a client.
 */
class LeadResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Main';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Sales;
    }

    public static function getNavigationBadge(): ?string
    {
        $due = Lead::query()->toContact()->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('To contact today');
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Lead|null $record */
        return $record?->displayName() ?? '';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'company', 'email', 'phone'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Contact'))
                ->columns(2)
                ->schema([
                    TextInput::make('first_name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->maxLength(255),
                    TextInput::make('company')
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(50),
                    Select::make('country')
                        ->options(fn () => Countries::getNames('en'))
                        ->searchable()
                        ->default(fn () => Region::country()),
                ]),
            Section::make(__('Lead'))
                ->columns(2)
                ->schema([
                    Select::make('source')
                        ->options(fn (?Lead $record) => self::sourceOptions($record?->source))
                        ->placeholder(__('Unknown')),
                    Select::make('status')
                        ->options(fn (?Lead $record) => collect($record && ! $record->status->isOpen() ? [$record->status] : LeadStatus::open())
                            ->mapWithKeys(fn (LeadStatus $status) => [$status->value => $status->getLabel()])->all())
                        ->default(LeadStatus::New->value)
                        ->selectablePlaceholder(false)
                        ->disabled(fn (?Lead $record) => $record !== null && ! $record->status->isOpen())
                        ->helperText(fn (?Lead $record) => $record !== null && ! $record->status->isOpen() ? null : __('Won and Lost come from Convert to client and Mark as lost.')),
                    Select::make('assigned_to')
                        ->label('Assigned to')
                        ->placeholder(__('Nobody'))
                        ->options(fn () => User::query()->admins()->where('active', true)->orderBy('name')->get()
                            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Sales))
                            ->pluck('name', 'id'))
                        ->default(fn () => auth()->id()),
                    DatePicker::make('next_contact_on')
                        ->label('Next contact')
                        ->native(false)
                        ->displayFormat(AppServiceProvider::dateFormat())
                        ->helperText(__('That morning the bell reminds whoever it is assigned to (else everyone in Sales).')),
                    Textarea::make('notes')
                        ->label('Looking for')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, string> the sources of Settings → Sales, plus the lead's own if it was renamed there since */
    public static function sourceOptions(?string $current = null): array
    {
        $sources = Leads::sources();
        if (filled($current) && ! in_array($current, $sources, true)) {
            $sources[] = $current;
        }

        return array_combine($sources, $sources);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->state(fn (Lead $record) => $record->name)
                    ->description(fn (Lead $record) => $record->company)
                    ->searchable(['first_name', 'last_name', 'company', 'email', 'phone'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('email')
                    ->description(fn (Lead $record) => $record->phone)
                    ->placeholder('–'),
                TextColumn::make('source')
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (Lead $record) => $record->status === LeadStatus::Lost ? $record->lost_reason : null),
                TextColumn::make('next_contact_on')
                    ->label('Next contact')
                    ->date(AppServiceProvider::dateFormat())
                    ->color(fn (Lead $record) => $record->status->isOpen() && $record->next_contact_on?->lte(today()) ? 'danger' : null)
                    ->placeholder('–')
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Assigned to')
                    ->placeholder(__('Nobody'))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('next_contact_on is null')->orderBy('next_contact_on')->latest('id'))
            ->filters([
                SelectFilter::make('source')
                    ->options(fn () => self::sourceOptions() + Lead::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source', 'source')->all()),
                SelectFilter::make('assigned_to')
                    ->label('Assigned to')
                    ->relationship('assignee', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading(__('No leads yet'))
            ->emptyStateDescription(__('Add the people who asked about your services, so none is forgotten until they become a client.'));
    }

    public static function getRelations(): array
    {
        return [
            NotesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }

    /** The client a won lead became, when the admin may open clients. */
    public static function clientUrl(Lead $lead): ?string
    {
        return $lead->client_id && ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $lead->client_id]) : null;
    }
}
