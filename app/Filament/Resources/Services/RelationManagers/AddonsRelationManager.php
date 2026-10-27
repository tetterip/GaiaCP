<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Actions\AddAddon;
use App\Enums\ServiceStatus;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Product;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** A service's add-ons (App\Actions\AddAddon): each a service of its own, on the same cycle and due date. */
class AddonsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'addons';

    protected static ?string $title = 'Add-ons';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Service $ownerRecord */
        return $ownerRecord->addons()->exists()
            || (AddAddon::canHaveAddons($ownerRecord) && Product::query()->addonsFor($ownerRecord->product)->exists());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label'),
                TextColumn::make('price_cents')
                    ->label('Price per cycle')
                    ->formatStateUsing(fn (Service $record) => Money::format($record->price_cents, $record->currency)),
                TextColumn::make('next_due_date')
                    ->label('Next due date')
                    ->date(AppServiceProvider::dateFormat()),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->headerActions([
                Action::make('addAddon')
                    ->label(__('Add add-on'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => AddAddon::canHaveAddons($this->getOwnerRecord()))
                    ->modalDescription(fn () => $this->getOwnerRecord()->status === ServiceStatus::Pending
                        ? __('Added to the same order and paid with its first payment.')
                        : __('Starts today on the same cycle; first owed on :date, with the service, setup fee included. The days until then are not charged.', ['date' => $this->getOwnerRecord()->next_due_date->format(AppServiceProvider::dateFormat())]))
                    ->schema([
                        Select::make('product_id')
                            ->label('Add-on')
                            ->options(fn () => Product::query()->addonsFor($this->getOwnerRecord()->product)->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                $service = $this->getOwnerRecord();
                                $price = $state ? Product::find($state)?->priceFor($service->billing_cycle, $service->currency) : null;
                                $set('price', $price === null ? null : Money::toDecimal($price));
                                $set('label', $state ? Product::find($state)?->name : null);
                            }),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('price')
                            ->label(fn () => __('Price per cycle (:cycle)', ['cycle' => $this->getOwnerRecord()->billing_cycle->getLabel()]))
                            ->prefix(fn () => $this->getOwnerRecord()->currency)
                            ->required()
                            ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                            ->helperText(fn (Get $get) => $get('product_id') && ($product = Product::find($get('product_id')))
                                && $product->priceFor($this->getOwnerRecord()->billing_cycle) === null
                                    ? __('This add-on has no price for this cycle: enter one.')
                                    : null),
                    ])
                    ->action(function (array $data) {
                        $addon = app(AddAddon::class)->handle(
                            $this->getOwnerRecord(),
                            Product::findOrFail($data['product_id']),
                            Money::toCents($data['price']),
                            $data['label'],
                        );

                        Notification::make()->title(__(':addon added', ['addon' => $addon->label]))->success()->send();
                    }),
            ]);
    }
}
