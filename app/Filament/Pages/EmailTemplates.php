<?php

namespace App\Filament\Pages;

use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\EmailTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every email GaiaCP sends (App\Enums\MailTemplate), grouped as in WHMCS, with whether it is sent
 * automatically and whether its text was edited. Reached from Settings → Email templates; each row
 * opens EditEmailTemplate.
 */
class EmailTemplates extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static ?int $navigationSort = 61;

    protected static ?string $title = 'Email templates';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::EmailSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Every email sent to clients: on or off, subject and text with placeholders.');
    }

    /** Stays highlighted while a template is open (EditEmailTemplate's route is under this one). */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return static::getRouteName().'*';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $stored = EmailTemplate::query()->get()->keyBy('key');

                return collect(MailTemplate::cases())
                    ->sortBy(fn (MailTemplate $t) => array_search($t->group(), MailTemplate::GROUPS, true))
                    ->mapWithKeys(fn (MailTemplate $t) => [$t->value => [
                        'key' => $t->value,
                        'group' => $t->group(),
                        'name' => $t->label(),
                        'when' => $t->sentWhen(),
                        'enabled' => $stored[$t->value]->enabled ?? $t->enabledByDefault(),
                        'customized' => filled($stored[$t->value]->subject ?? null) || filled($stored[$t->value]->body ?? null),
                    ]])
                    ->all();
            })
            ->columns([
                TextColumn::make('group')->formatStateUsing(fn (string $state) => __($state))->badge()->color('gray'),
                TextColumn::make('name')->weight('medium')->description(fn (array $record) => $record['when'])->wrap(),
                IconColumn::make('enabled')->label('Sent')->boolean(),
                TextColumn::make('customized')
                    ->label('Text')
                    ->formatStateUsing(fn (bool $state) => $state ? __('Edited') : __('Built-in'))
                    ->badge()
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordUrl(fn (array $record) => EditEmailTemplate::getUrl(['template' => $record['key']]))
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (array $record) => EditEmailTemplate::getUrl(['template' => $record['key']])),
            ])
            ->paginated(false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
