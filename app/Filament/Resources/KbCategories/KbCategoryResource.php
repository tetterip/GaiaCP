<?php

namespace App\Filament\Resources\KbCategories;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\KbArticles\KbCategoryForm;
use App\Filament\Resources\KbCategories\Pages\ManageKbCategories;
use App\Models\KbCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Knowledge base categories: the groups of Support → Knowledge base; deleting one keeps its articles, without a category. */
class KbCategoryResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = KbCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 53;

    protected static ?string $navigationLabel = 'Knowledge base categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'Knowledge base categories';

    protected static ?string $slug = 'knowledge-base-categories';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function settingsDescription(): string
    {
        return __('The groups of the knowledge base articles in the client area.');
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
                    ->state(fn (KbCategory $record) => $record->name()),
                TextColumn::make('articles_count')
                    ->label('Articles')
                    ->counts('articles'),
                TextColumn::make('sort')
                    ->label('Position')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('Its articles stay, without a category.')),
            ])
            ->emptyStateHeading(__('No categories yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageKbCategories::route('/'),
        ];
    }
}
