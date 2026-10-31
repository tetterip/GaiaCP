<?php

namespace App\Filament\Resources\Downloads;

use App\Enums\AdminArea;
use App\Enums\DownloadVisibility;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Downloads\Pages\ManageDownloads;
use App\Filament\Resources\KbArticles\KbCategoryForm;
use App\Models\Download;
use App\Models\DownloadCategory;
use App\Models\Product;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Support → Downloads: files for the client area's Downloads page (App\Filament\Client\Pages\Downloads), a title and
 * description per language, in categories (Settings → Download categories), for everyone, clients or the clients of
 * chosen products. Files stay on the private disk; DownloadController hands them out.
 */
class DownloadResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Download::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Downloads';

    protected static ?string $modelLabel = 'download';

    protected static ?string $pluralModelLabel = 'Downloads';

    protected static ?string $slug = 'downloads';

    protected static bool $isGloballySearchable = false;

    /** The largest file Livewire takes by default, in KB; PHP's upload limits may be lower. */
    public const MAX_KB = 12288;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Download ? $record->title() : '';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            FileUpload::make('path')
                ->label('File')
                ->required()
                ->disk('local')
                ->directory(Download::FOLDER)
                ->visibility('private')
                ->storeFileNamesIn('file_name')
                ->maxSize(self::maxKb())
                ->downloadable(false)
                ->previewable(false)
                ->helperText(__('Up to :size. Clients download it through GaiaCP, which checks who may have it; it never has a public address.', ['size' => Number::fileSize(self::maxKb() * 1024)])),
            Grid::make(2)->schema([
                Select::make('download_category_id')
                    ->label('Category')
                    ->placeholder(__('No category'))
                    ->options(fn () => DownloadCategory::query()->ordered()->get()->mapWithKeys(fn (DownloadCategory $category) => [$category->id => $category->name()]))
                    ->createOptionForm(KbCategoryForm::fields())
                    ->createOptionUsing(fn (array $data) => DownloadCategory::create($data)->getKey()),
                TextInput::make('sort')
                    ->label('Position')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->helperText(__('Smaller numbers come first.')),
                Select::make('visibility')
                    ->label('Who can download it')
                    ->options(DownloadVisibility::options())
                    ->default(DownloadVisibility::Clients->value)
                    ->required()
                    ->live()
                    ->native(false),
                Toggle::make('published')
                    ->label('Published')
                    ->default(true)
                    ->inline(false),
                Select::make('product_ids')
                    ->label('Products')
                    ->multiple()
                    ->options(fn () => Product::query()->orderBy('name')->get()->mapWithKeys(fn (Product $product) => [$product->id => $product->localName()]))
                    ->searchable()
                    ->required(fn (Get $get) => $get('visibility') === DownloadVisibility::Products->value)
                    ->visible(fn (Get $get) => $get('visibility') === DownloadVisibility::Products->value)
                    ->helperText(__('Clients with an active service of one of these products.'))
                    ->columnSpanFull(),
            ]),
            self::texts(),
        ]);
    }

    /** A title and description per language; only the default language's title is required. */
    private static function texts(): Tabs|Section
    {
        $fields = fn (string $locale) => [
            TextInput::make("texts.{$locale}.title")
                ->label('Title')
                ->maxLength(200)
                ->required(Locales::isDefault($locale)),
            Textarea::make("texts.{$locale}.description")
                ->label('Description')
                ->rows(3)
                ->maxLength(2000),
        ];

        if (! Locales::multiple()) {
            return Section::make()->schema($fields(Locales::default()));
        }

        return Tabs::make('texts')->tabs(collect(Locales::available())
            ->map(fn (string $name, string $locale) => Tab::make($name)->schema($fields($locale)))
            ->values()->all());
    }

    public static function maxKb(): int
    {
        $php = collect([ini_get('upload_max_filesize'), ini_get('post_max_size')])
            ->map(fn ($value) => self::iniKb((string) $value))
            ->filter(fn (int $kb) => $kb > 0);

        return (int) min(self::MAX_KB, $php->min() ?? self::MAX_KB);
    }

    private static function iniKb(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 * 1024,
            'M' => $number * 1024,
            'K' => $number,
            default => intdiv($number, 1024),
        };
    }

    /**
     * Before saving: only a file the field has just stored (downloads/ + 40 random characters, as hashName() gives),
     * or the one the download already has. Filament keeps any string the browser sends, so "downloads/../backups/…"
     * would otherwise hand out another file. Also notes its size.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function checkFile(array $data, ?Download $record = null): array
    {
        $path = $data['path'] ?? null;

        if (is_array($path)) {
            $path = reset($path) ?: null;
        }

        $kept = $record !== null && $path === $record->path;
        $new = is_string($path) && preg_match('~\A'.Download::FOLDER.'/[A-Za-z0-9]{40}(\.[A-Za-z0-9]{1,10})?\z~', $path)
            && ! Download::query()->where('path', $path)->exists();

        if (! $kept && ! $new) {
            throw ValidationException::withMessages(['path' => __('Upload the file again.')]);
        }

        $data['path'] = $path;
        $data['file_name'] = basename(str_replace('\\', '/', (string) ($data['file_name'] ?? ''))) ?: ($record?->file_name ?? basename($path));
        $data['size'] = Storage::disk('local')->exists($path) ? Storage::disk('local')->size($path) : ($record?->size ?? 0);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('category'))
            ->columns([
                TextColumn::make('title')
                    ->state(fn (Download $record) => $record->title())
                    ->description(fn (Download $record) => $record->category?->name())
                    ->wrap(),
                TextColumn::make('file_name')
                    ->label('File')
                    ->description(fn (Download $record) => $record->humanSize())
                    ->toggleable(),
                TextColumn::make('visibility')
                    ->label('Who can download it')
                    ->formatStateUsing(fn (DownloadVisibility $state) => $state->label())
                    ->badge()
                    ->color('gray'),
                IconColumn::make('published')
                    ->boolean(),
                TextColumn::make('download_count')
                    ->label('Times downloaded')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('download_category_id')->orderBy('sort')->orderBy('id'))
            ->filters([
                SelectFilter::make('download_category_id')
                    ->label('Category')
                    ->options(fn () => DownloadCategory::query()->ordered()->get()->mapWithKeys(fn (DownloadCategory $category) => [$category->id => $category->name()])),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data, Download $record) => self::checkFile($data, $record)),
                DeleteAction::make()
                    ->modalDescription(__('The file is deleted too.')),
            ])
            ->emptyStateHeading(__('No downloads yet'))
            ->emptyStateDescription(__('Files for your clients, such as guides, software or forms, for everyone or only the clients of some products.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDownloads::route('/'),
        ];
    }
}
