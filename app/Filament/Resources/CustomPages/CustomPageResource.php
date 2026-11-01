<?php

namespace App\Filament\Resources\CustomPages;

use App\Enums\AdminArea;
use App\Filament\Client\Pages\CustomPageView;
use App\Filament\Clusters\Settings\Pages\MenuSettings;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\CustomPages\Pages\CreateCustomPage;
use App\Filament\Resources\CustomPages\Pages\EditCustomPage;
use App\Filament\Resources\CustomPages\Pages\ListCustomPages;
use App\Models\CustomPage;
use App\Providers\AppServiceProvider;
use App\Support\Locales;
use App\Support\PageImages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * Support → Pages: pages of your own for the client area and the public area (About us, Terms, a guide), written with
 * the editor (headings, lists, links, tables, pictures), a title and text per language, at /pages/{slug}. Settings →
 * Client area → Menus adds them to the side menus.
 */
class CustomPageResource extends Resource
{
    /** Under the title of the list and of each page: pages go into the side menus from Settings → Client area → Menus (a link when it opens). */
    public static function menusHint(): HtmlString
    {
        $where = e(__('Settings → Client area → Menus'));

        if (MenuSettings::canAccess()) {
            $where = '<a href="'.e(MenuSettings::getUrl()).'" class="text-primary-600 underline dark:text-primary-400">'.$where.'</a>';
        }

        return new HtmlString(__('To show a page in the side menu of clients or visitors, add it under :menus.', ['menus' => $where]));
    }

    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = CustomPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $modelLabel = 'page';

    protected static ?string $pluralModelLabel = 'Pages';

    protected static ?string $slug = 'pages';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof CustomPage ? $record->title() : '';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Toggle::make('published')
                        ->label('Published')
                        ->default(true)
                        ->inline(false)
                        ->helperText(__('Off: a draft nobody sees.')),
                    Toggle::make('clients_only')
                        ->label('Only for clients')
                        ->inline(false)
                        ->helperText(__('Visitors are asked to log in first, and search engines leave it out.')),
                    TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/pages/')
                        ->maxLength(100)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Empty: made from the title.')),
                    FileUpload::make('image')
                        ->label('Featured image')
                        ->image()
                        ->disk(PageImages::DISK)
                        ->directory(PageImages::FOLDER)
                        ->visibility('public')
                        ->acceptedFileTypes(PageImages::TYPES)
                        ->maxSize(PageImages::MAX_KB)
                        ->imagePreviewHeight('160')
                        ->columnSpanFull()
                        ->helperText(__('Shown above the text, and as the picture when the page is shared (Facebook, Viber, Slack…). About 1200 × 630 pixels works best.'))
                        ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => PageImages::store($file))
                        // The preview comes through the page images route, so it shows without storage:link
                        ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                            $uploaded = $component->getUploadedFile($file, $storedFileNames);

                            if ($uploaded !== null) {
                                $uploaded['url'] = PageImages::url($file) ?? $uploaded['url'];
                            }

                            return $uploaded;
                        }),
                ]),
            self::texts(),
        ]);
    }

    /** A title, text and description per language; only the default language's title and text are required. */
    private static function texts(): Tabs|Section
    {
        $fields = fn (string $locale) => [
            TextInput::make("texts.{$locale}.title")
                ->label('Title')
                ->maxLength(200)
                ->required(Locales::isDefault($locale)),
            self::editor("texts.{$locale}.body")
                ->label('Text')
                ->required(Locales::isDefault($locale))
                ->helperText(Locales::isDefault($locale) ? null : __('Leave empty to show the page in the first language.')),
            Textarea::make("texts.{$locale}.description")
                ->label('Description for search engines')
                ->rows(2)
                ->maxLength(300)
                ->helperText(__('A line or two shown under the title in search results and link previews. Empty: the first lines of the text.')),
        ];

        if (! Locales::multiple()) {
            return Section::make()->schema($fields(Locales::default()));
        }

        return Tabs::make('texts')->tabs(collect(Locales::available())
            ->map(fn (string $name, string $locale) => Tab::make($name)->schema($fields($locale)))
            ->values()->all());
    }

    /** The editor: formatting, links, tables and pictures (App\Support\PageImages), nothing that could run a script. */
    public static function editor(string $name): RichEditor
    {
        return RichEditor::make($name)
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['h2', 'h3'],
                ['alignStart', 'alignCenter', 'alignEnd'],
                ['blockquote', 'bulletList', 'orderedList', 'horizontalRule'],
                ['table', 'attachFiles'],
                ['undo', 'redo'],
            ])
            ->fileAttachmentsAcceptedFileTypes(PageImages::TYPES)
            ->fileAttachmentsMaxSize(PageImages::MAX_KB)
            ->saveUploadedFileAttachmentUsing(fn ($file) => PageImages::store($file))
            ->getFileAttachmentUrlUsing(fn ($file) => PageImages::url($file))
            ->extraInputAttributes(['style' => 'min-height: 16rem']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->state(fn (CustomPage $record) => $record->title())
                    ->description(fn (CustomPage $record) => '/pages/'.$record->slug)
                    ->wrap(),
                IconColumn::make('published')
                    ->label('Published')
                    ->boolean(),
                IconColumn::make('clients_only')
                    ->label('Only for clients')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Changed')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (CustomPage $record) => $record->published)
                    ->url(fn (CustomPage $record) => CustomPageView::urlFor($record), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No pages yet'))
            ->emptyStateDescription(__('Pages of your own, such as About us or your terms, written with the editor. Add them to the side menus under Settings → Client area → Menus.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomPages::route('/'),
            'create' => CreateCustomPage::route('/create'),
            'edit' => EditCustomPage::route('/{record}/edit'),
        ];
    }
}
