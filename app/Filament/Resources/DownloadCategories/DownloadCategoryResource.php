<?php

namespace App\Filament\Resources\DownloadCategories;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\DownloadCategories\Pages\ManageDownloadCategories;
use App\Filament\Resources\KbArticles\KbCategoryForm;
use App\Models\DownloadCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Download categories: the groups of Support → Downloads; deleting one keeps its files, without a category. */
class DownloadCategoryResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = DownloadCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderArrowDown;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 54;

    protected static ?string $navigationLabel = 'Download categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'Download categories';

    protected static ?string $slug = 'download-categories';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function settingsDescription(): string
    {
        return __('The groups of the files in Support → Downloads.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(KbCategoryForm::fields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->state(fn (DownloadCategory $record) => $record->name()),
                TextColumn::make('downloads_count')
                    ->label('Files')
                    ->counts('downloads'),
                TextColumn::make('sort')
                    ->label('Position')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('Its files stay, without a category.')),
            ])
            ->emptyStateHeading(__('No categories yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDownloadCategories::route('/'),
        ];
    }
}
