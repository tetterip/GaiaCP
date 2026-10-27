<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Clusters\Reports\ReportCard;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Reports\SalesOpportunities as Opportunities;
use App\Support\MailTemplates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Reports → Sales opportunities (App\Reports\SalesOpportunities): a tab per kind, each row with an Email button that
 * opens the kind's template in the client's language to edit before it is sent. "Emailed" is the last time it was.
 */
class SalesOpportunities extends Page implements HasTable, ReportCard
{
    use HasTabs;
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static ?string $cluster = ReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Sales opportunities';

    protected static ?string $slug = 'sales-opportunities';

    #[Url]
    public ?string $activeTab = null;

    /** @var array<string, list<int>> */
    private array $ids = [];

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Reports;
    }

    public static function reportDescription(): string
    {
        return __('Domains to transfer to you, domains without hosting and expired domains still recoverable, each with an email.');
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getTabsContentComponent(), EmbeddedTable::make()]);
    }

    public function getTabs(): array
    {
        return collect(Opportunities::KINDS)->mapWithKeys(fn (string $kind) => [$kind => Tab::make(Opportunities::label($kind))
            ->badge(count($this->ids($kind)))
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('services.id', $this->ids($kind)))])->all();
    }

    /** @return list<int> */
    private function ids(string $kind): array
    {
        return $this->ids[$kind] ??= Opportunities::ids($kind);
    }

    private function kind(): string
    {
        return in_array($this->activeTab, Opportunities::KINDS, true) ? $this->activeTab : Opportunities::KINDS[0];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->modifyQueryWithActiveTab(Service::query()
                ->select('services.*')
                ->addSelect(['offer_emailed_at' => ActivityLog::query()
                    ->select('created_at')
                    ->whereColumn('service_id', 'services.id')
                    ->where('event', 'email.sent')
                    ->where('properties->template', Opportunities::template($this->kind())->value)
                    ->latest('created_at')
                    ->limit(1)])
                ->with(['client', 'domainDetails'])))
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (Service $record) => $record->client?->name)
                    ->searchable(['first_name', 'last_name', 'company'])
                    ->url(fn (Service $record) => ClientResource::getUrl('edit', ['record' => $record->client_id])),
                TextColumn::make('detail')
                    ->label('Why')
                    ->state(fn (Service $record) => Opportunities::detail($this->kind(), $record)),
                TextColumn::make('offer_emailed_at')
                    ->label('Emailed')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i')
                    ->placeholder(__('Not yet'))
                    ->sortable(),
            ])
            ->recordActions([$this->emailAction()])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('Nothing here right now'));
    }

    /** The kind's template in the client's language, to read and edit; placeholders are filled in when sent. */
    private function emailAction(): Action
    {
        return Action::make('email')
            ->label('Email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Service $record) => filled($record->client?->email))
            ->modalHeading(fn (Service $record) => __('Email :client', ['client' => $record->client->name]))
            ->modalDescription(fn (Service $record) => __('To :email. Placeholders such as {$domain} are filled in when it is sent; this text is not saved to the template.', ['email' => $record->client->email]))
            ->fillForm(fn (Service $record) => array_intersect_key(
                MailTemplates::get(Opportunities::template($this->kind()), $record->client->preferredLocale()),
                array_flip(['subject', 'body']),
            ))
            ->schema(fn (Service $record) => [
                TextInput::make('subject')->required()->maxLength(255),
                AiTextActions::on(Textarea::make('body')->label('Message')->required()->rows(14), clientLanguage: fn () => $record->client?->locale),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (Service $record, array $data) {
                $kind = $this->kind();
                $sent = app(ClientMailer::class)->send(
                    Opportunities::template($kind),
                    $record->client,
                    fn () => Opportunities::values($kind, $record),
                    $record,
                    evenIfOff: true,
                    text: ['subject' => (string) $data['subject'], 'body' => (string) $data['body']],
                );

                $sent
                    ? Notification::make()->success()->title(__('Sent to :email', ['email' => $record->client->email]))->send()
                    : Notification::make()->danger()->title(__('The email could not be sent'))->body(__('See the email log.'))->send();
            });
    }
}
