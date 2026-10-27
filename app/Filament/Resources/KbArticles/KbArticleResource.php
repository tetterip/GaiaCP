<?php

namespace App\Filament\Resources\KbArticles;

use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\KbArticles\Pages\CreateKbArticle;
use App\Filament\Resources\KbArticles\Pages\EditKbArticle;
use App\Filament\Resources\KbArticles\Pages\ListKbArticles;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Providers\AppServiceProvider;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Support → Knowledge base: help articles for the client area (App\Filament\Client\Pages\KnowledgeBase), a title and
 * Markdown text per language, in categories (Settings → Knowledge base categories), shown once published.
 */
class KbArticleResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = KbArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Knowledge base';

    protected static ?string $modelLabel = 'article';

    protected static ?string $pluralModelLabel = 'Knowledge base';

    protected static ?string $slug = 'knowledge-base';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof KbArticle ? $record->title() : '';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('kb_category_id')
                        ->label('Category')
                        ->placeholder(__('No category'))
                        ->options(fn () => KbCategory::query()->ordered()->get()->mapWithKeys(fn (KbCategory $category) => [$category->id => $category->name()]))
                        ->createOptionForm(KbCategoryForm::fields())
                        ->createOptionUsing(fn (array $data) => KbCategory::create($data)->getKey()),
                    TextInput::make('sort')
                        ->label('Position')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->helperText(__('Smaller numbers come first.')),
                    Toggle::make('published')
                        ->label('Published')
                        ->helperText(__('Clients see it in the client area.')),
                    Toggle::make('clients_only')
                        ->label('Only for clients')
                        ->helperText(__('Visitors of the public knowledge base (Settings → Public area) don\'t see it; they are asked to log in.')),
                    TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/knowledgebase/')
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
                ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList', 'codeBlock'], ['undo', 'redo']])
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
            ->modifyQueryUsing(fn ($query) => $query->with('category'))
            ->columns([
                TextColumn::make('title')
                    ->state(fn (KbArticle $record) => $record->title())
                    ->description(fn (KbArticle $record) => $record->category?->name())
                    ->searchable(query: fn ($query, string $search) => $query->matching($search))
                    ->wrap(),
                IconColumn::make('published')
                    ->boolean(),
                IconColumn::make('clients_only')
                    ->label('Only for clients')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('views')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('helpful')
                    ->label('Helpful')
                    ->state(fn (KbArticle $record) => $record->helpful_yes + $record->helpful_no > 0
                        ? __(':yes yes, :no no', ['yes' => $record->helpful_yes, 'no' => $record->helpful_no])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('kb_category_id')->orderBy('sort')->orderBy('id'))
            ->filters([
                SelectFilter::make('kb_category_id')
                    ->label('Category')
                    ->options(fn () => KbCategory::query()->ordered()->get()->mapWithKeys(fn (KbCategory $category) => [$category->id => $category->name()])),
                TernaryFilter::make('published'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No articles yet'))
            ->emptyStateDescription(__('Answers to what clients often ask, so they find them without a ticket.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKbArticles::route('/'),
            'create' => CreateKbArticle::route('/create'),
            'edit' => EditKbArticle::route('/{record}/edit'),
        ];
    }
}
