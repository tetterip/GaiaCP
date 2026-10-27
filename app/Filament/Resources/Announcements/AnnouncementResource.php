<?php

namespace App\Filament\Resources\Announcements;

use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Providers\AppServiceProvider;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Support → Announcements: news for the client area's Announcements page (App\Filament\Client\Pages\Announcements),
 * a title and Markdown text per language, shown from its publish date; public ones also to visitors and in the feed
 * while Settings → Public area → Announcements is on.
 */
class AnnouncementResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $modelLabel = 'announcement';

    protected static ?string $pluralModelLabel = 'Announcements';

    protected static ?string $slug = 'announcements';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Announcement ? $record->title() : '';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    DateTimePicker::make('published_at')
                        ->label('Publish on')
                        ->seconds(false)
                        ->default(now())
                        ->helperText(__('Empty: a draft nobody sees. A later date publishes it then.')),
                    Toggle::make('clients_only')
                        ->label('Only for clients')
                        ->inline(false)
                        ->helperText(__('Visitors of the public area and the feed don\'t get it; clients see it once they log in.')),
                    TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/announcements/')
                        ->maxLength(100)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Empty: made from the title.')),
                ]),
            self::texts(),
        ]);
    }

    /** A title and text per language; only the default language's is required, the others fall back to it. */
    private static function texts(): Tabs|Section
    {
        $fields = fn (string $locale) => [
            TextInput::make("texts.{$locale}.title")
                ->label('Title')
                ->maxLength(200)
                ->required(Locales::isDefault($locale)),
            AiTextActions::forLocale(MarkdownEditor::make("texts.{$locale}.body")
                ->label('Text')
                ->required(Locales::isDefault($locale))
                ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']])
                ->helperText(Locales::isDefault($locale) ? null : __('Leave empty to show the text of the first language.')), $locale, fn (string $other) => "texts.{$other}.body"),
        ];

        if (! Locales::multiple()) {
            return Section::make()->schema($fields(Locales::default()));
        }

        return Tabs::make('texts')->tabs(collect(Locales::available())
            ->map(fn (string $name, string $locale) => Tab::make($name)->schema($fields($locale)))
            ->values()->all());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->state(fn (Announcement $record) => $record->title())
                    ->wrap(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i')
                    ->placeholder(__('Draft'))
                    ->sortable(),
                IconColumn::make('clients_only')
                    ->label('Only for clients')
                    ->boolean(),
            ])
            ->defaultSort(fn ($query) => $query->orderByRaw('published_at is null desc')->orderByDesc('published_at')->orderByDesc('id'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No announcements yet'))
            ->emptyStateDescription(__('News for your clients: new services, price changes, planned maintenance.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
