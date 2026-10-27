<?php

namespace App\Filament\Clusters\Settings;

use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\Settings as SettingsStore;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

/**
 * One settings page: a form over some of App\Support\Settings::keys() with its own Save. Saving stores only this
 * page's keys, so the other pages' values are untouched. New editable setting: a default in config/gaiacp.php,
 * the key in Settings::keys() and in keys() of the page that edits it, and a field on that page.
 */
abstract class SettingsFormPage extends Page implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static ?string $cluster = SettingsCluster::class;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @return list<string> the Settings::keys() this page edits */
    abstract public static function keys(): array;

    public function mount(): void
    {
        $values = Arr::only(SettingsStore::all(), static::keys());

        foreach (array_intersect(SettingsStore::encrypted(), static::keys()) as $key) {
            $values[$key] = null; // never sent to the browser; blank keeps the stored one
        }

        $this->form->fill(Arr::undot($this->forForm($values)));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.settings.styles'),
            ...$this->beforeForm(),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    // Kept in view at the foot of the screen; it says so once a field changes, until saved or undone
                    Flex::make([
                        Text::make(__('You have unsaved changes'))
                            ->color('gray')
                            ->extraAttributes(['x-show' => 'dirty', 'x-cloak' => true, 'class' => 'gx-save-bar-note']),
                        Actions::make([
                            Action::make('save')->label('Save')->submit('save'),
                            Action::make('discard')
                                ->label('Cancel')
                                ->color('gray')
                                ->extraAttributes(['x-show' => 'dirty', 'x-cloak' => true])
                                ->action(fn () => $this->discard()),
                        ])->alignEnd(), // end-aligned actions are laid out from the end, so Save is rightmost
                    ])
                        ->extraAttributes([
                            'class' => 'gx-save-bar',
                            'x-data' => '{ dirty: false }',
                            'x-init' => "const form = \$el.closest('form'); ['input', 'change'].forEach((e) => form?.addEventListener(e, () => dirty = true))",
                            'x-bind:class' => "{ 'gx-dirty': dirty }",
                            'x-on:settings-clean.window' => 'dirty = false',
                        ]),
                ]),
        ]);
    }

    /** "Settings / <group>" over the title (a payment plugin's page: its Payment methods parent). */
    public function getBreadcrumbs(): array
    {
        $breadcrumbs = SettingsCluster::unshiftClusterBreadcrumbs([]);
        $parent = static::getNavigationParentItem();

        if (filled($parent)) {
            $breadcrumbs[Pages\PaymentMethodsSettings::getUrl()] = $parent;
        } elseif (filled($group = static::getNavigationGroup())) {
            $breadcrumbs[] = __((string) $group);
        }

        return $breadcrumbs;
    }

    public function getSubheading(): ?string
    {
        return static::settingsDescription();
    }

    /** Cancel: the stored values back in the form. */
    public function discard(): void
    {
        $this->mount();
        $this->dispatch('settings-clean');
    }

    /** Shown above the form, e.g. PluginSwitch's On switch. */
    protected function beforeForm(): array
    {
        return [];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $values = collect(static::keys())->mapWithKeys(fn (string $key) => [$key => data_get($data, $key)])->all();
        $values = $this->forStorage($values);

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        foreach (array_intersect(SettingsStore::encrypted(), static::keys()) as $key) {
            if ($values[$key] === null) {
                unset($values[$key]); // left blank: keep the stored secret
            }
        }

        $previous = Arr::only(SettingsStore::all(), array_keys($values));

        SettingsStore::save($values);

        $this->afterSaving($values, $previous);

        foreach (array_intersect(SettingsStore::encrypted(), static::keys()) as $key) {
            data_set($this->data, $key, null);
        }

        $this->dispatch('settings-clean');

        Notification::make()->success()->title(__(':page settings saved', ['page' => $this->getTitle()]))->send();
    }

    /**
     * Stored values (dotted keys) → form state, e.g. casting to the types the fields expect.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function forForm(array $values): array
    {
        return $values;
    }

    /**
     * Form state (dotted keys) → values to store. Strings are trimmed and blanks become null afterwards.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function forStorage(array $values): array
    {
        return $values;
    }

    /**
     * @param  array<string, mixed>  $values  what was stored
     * @param  array<string, mixed>  $previous  the values they replaced
     */
    protected function afterSaving(array $values, array $previous): void {}
}
