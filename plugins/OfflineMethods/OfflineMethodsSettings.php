<?php

namespace Plugins\OfflineMethods;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Models\Payment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Your own ways to pay: name, instructions, on or off. A method used by payments is turned off, never removed. */
class OfflineMethodsSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 29;

    protected static ?string $title = 'Other ways to pay';

    protected static ?string $slug = 'other-ways-to-pay';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Your own ways to pay without a gateway, each with instructions for the client.');
    }

    public static function pluginId(): string
    {
        return 'offline-methods';
    }

    public static function keys(): array
    {
        return (new OfflineMethodsPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Each one is a payment method of its own: its instructions show on payment notices and their emails, and you pick it when you record a payment. In the instructions, {number} becomes the payment notice\'s number (the reference to write) and {amount} its total.'))
                    ->schema([
                        Repeater::make('offline_methods.methods')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel(__('Add a way to pay'))
                            ->itemLabel(fn (array $state) => $state['name'] ?? null)
                            ->collapsible()
                            // A method payments were recorded with is turned off instead, so they keep its name
                            ->deleteAction(fn (Action $action) => $action->hidden(fn (array $arguments, Repeater $component) => self::used($component->getRawItemState($arguments['item'])['key'] ?? null)))
                            ->schema([
                                Hidden::make('key'),
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(60)
                                    ->placeholder(__('Cash at our office')),
                                Textarea::make('instructions')
                                    ->rows(3)
                                    ->maxLength(1000)
                                    ->placeholder(__('Pay at our office, Mon–Fri 9:00–17:00. Mention {number}.')),
                                Toggle::make('active')
                                    ->default(true)
                                    ->helperText(__('Off: no longer offered or shown; payments made with it keep it.')),
                            ]),
                    ]),
            ]);
    }

    /** Whether payments were recorded with this method's key. */
    private static function used(?string $key): bool
    {
        return filled($key) && Payment::query()->where('method', $key)->exists();
    }

    protected function forForm(array $values): array
    {
        $values['offline_methods.methods'] = OfflineMethodsPlugin::methods();

        return $values;
    }

    protected function forStorage(array $values): array
    {
        // Every key in use (saved methods, payments), so a new one never takes another's; offline_ keeps off the others'
        $taken = [
            ...array_column(OfflineMethodsPlugin::methods(), 'key'),
            ...Payment::query()->distinct()->pluck('method')->filter()->all(),
        ];
        $methods = [];

        foreach (array_values((array) ($values['offline_methods.methods'] ?? [])) as $method) {
            $key = (string) ($method['key'] ?? '');
            if (! str_starts_with($key, 'offline_')) {
                $key = OfflineMethodsPlugin::keyFor((string) $method['name'], [...$taken, ...array_column($methods, 'key')]);
            }

            $methods[] = [
                'key' => $key,
                'name' => trim((string) $method['name']),
                'instructions' => filled($method['instructions'] ?? null) ? trim((string) $method['instructions']) : null,
                'active' => (bool) ($method['active'] ?? true),
            ];
        }

        $values['offline_methods.methods'] = $methods;

        return $values;
    }
}
