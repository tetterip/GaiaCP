<?php

namespace App\Filament\Resources\CannedReplies;

use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\CannedReplies\Pages\ManageCannedReplies;
use App\Models\CannedReply;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Canned replies: answers an admin starts a ticket reply from (App\Actions\Tickets::fill() fills in their placeholders). */
class CannedReplyResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = CannedReply::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 52;

    protected static ?string $navigationLabel = 'Canned replies';

    protected static ?string $modelLabel = 'canned reply';

    protected static ?string $pluralModelLabel = 'Canned replies';

    protected static ?string $slug = 'canned-replies';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'title';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function settingsDescription(): string
    {
        return __('Ready-made answers to start a ticket reply from.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(150),
                AiTextActions::on(Textarea::make('body')
                    ->label('Text')
                    ->required()
                    ->rows(10)
                    ->maxLength(20000)
                    ->helperText(__('You can use {client_first_name}, {client_name}, {ticket_number}, {ticket_subject} and {admin_name}.'))),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('body')->label('Text')->limit(90)->wrap(),
            ])
            ->defaultSort('title')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No canned replies yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCannedReplies::route('/'),
        ];
    }
}
