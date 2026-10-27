<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Actions\Leads;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The lead's history: dated notes by the admin who wrote them, newest first. A note on a New lead makes it Contacted. */
class NotesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'leadNotes';

    protected static ?string $title = 'Notes';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Lead $ownerRecord */
        $count = $ownerRecord->leadNotes()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Textarea::make('body')
                    ->label('Note')
                    ->placeholder(__('Called, wants two sites and email for five people…'))
                    ->required()
                    ->rows(4)
                    ->maxLength(5000),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label('Note')
                    ->wrap()
                    ->formatStateUsing(fn (string $state) => nl2br(e($state)))
                    ->html(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('–'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add note')
                    ->modalHeading(__('Add note'))
                    ->createAnother(false)
                    ->using(function (array $data) {
                        /** @var Lead $lead */
                        $lead = $this->getOwnerRecord();

                        return app(Leads::class)->addNote($lead, $data['body']);
                    })
                    ->after(fn () => $this->dispatch('refresh')),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn (LeadNote $record) => $record->user_id === auth()->id()),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('No notes yet'))
            ->emptyStateDescription(__('Write down each call or email, so whoever picks it up next knows where it stands.'));
    }
}
