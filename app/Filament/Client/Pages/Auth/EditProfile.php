<?php

namespace App\Filament\Client\Pages\Auth;

use App\Actions\ClientChangeRequests;
use App\Enums\ChangeRequestStatus;
use App\Filament\Concerns\KeepsDemoLogin;
use App\Filament\Concerns\ManagesPasskeys;
use App\Models\Client;
use App\Models\ClientChangeRequest;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\ClientFields;
use App\Support\Locales;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;
use Symfony\Component\Intl\Countries;

/**
 * The client's profile. Email and phone save at once on the client (their login follows, Client::booted()); name,
 * company, tax details and address go to the admins as a change request (ClientChangeRequests). Language, the bell, password,
 * two-factor authentication (Filament adds it) and passkeys (ManagesPasskeys) are the login's own.
 */
class EditProfile extends BaseEditProfile
{
    use KeepsDemoLogin;
    use ManagesPasskeys;

    /** On the right like every other form's (BasePage::alignFormActionsEnd()); Filament pins the profile's to the left. */
    public function getFormActionsAlignment(): string|Alignment
    {
        return static::$formActionsAlignment;
    }

    /** Shown after saving: the details went to the admins for approval. */
    private bool $requested = false;

    public function client(): Client
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user->client;
    }

    public function pendingRequest(): ?ClientChangeRequest
    {
        return $this->client()->changeRequests()->pending()->latest('id')->first();
    }

    /** The last answer to a request, while it is recent and nothing new is waiting. */
    public function recentDecision(): ?ClientChangeRequest
    {
        return $this->client()->changeRequests()
            ->where('status', '!=', ChangeRequestStatus::Pending)
            ->where('decided_at', '>=', now()->subDays(30))
            ->latest('decided_at')
            ->first();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Contact'))
                    ->columns(2)
                    ->schema([
                        $this->getEmailFormComponent()
                            ->label('Email address')
                            ->helperText(__('You log in with it, and our emails go to it.')),
                        TextInput::make('phone')
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(50)
                            ->helperText(__('With country code, e.g. +30 6912345678.')),
                    ]),

                Section::make(__('Your details'))
                    ->description(__('They appear on your payment notices and as the owner of your domains, so changes go to us for approval first.'))
                    ->schema([
                        Callout::make(__('Waiting for approval'))
                            ->description(fn () => ($pending = $this->pendingRequest()) ? __('You asked on :date to change: :fields. The form shows what you asked for.', [
                                'date' => $pending->updated_at->format(AppServiceProvider::dateFormat()),
                                'fields' => collect(array_keys($pending->fields))->map(ClientChangeRequests::label(...))->implode(', '),
                            ]) : null)
                            ->warning()
                            ->visible(fn () => $this->pendingRequest() !== null),
                        Callout::make(fn () => $this->recentDecision()?->status === ChangeRequestStatus::Approved ? __('Your changes were approved') : __('Your changes were declined'))
                            ->description(fn () => $this->recentDecision()?->status === ChangeRequestStatus::Approved ? null : __('Contact us if you have questions.'))
                            ->status(fn () => $this->recentDecision()?->status === ChangeRequestStatus::Approved ? 'success' : 'info')
                            ->visible(fn () => $this->pendingRequest() === null && $this->recentDecision() !== null),
                        Grid::make(2)
                            ->statePath('details')
                            ->schema([
                                TextInput::make('first_name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('last_name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('company')
                                    ->label('Company name')
                                    ->maxLength(255),
                                TextInput::make('profession')
                                    ->label('Profession')
                                    ->maxLength(255),
                                TextInput::make('vat_number')
                                    ->label('VAT number')
                                    ->maxLength(50),
                                TextInput::make('tax_office')
                                    ->label('Tax office')
                                    ->maxLength(255),
                                TextInput::make('address1')
                                    ->label('Address 1')
                                    ->maxLength(255),
                                TextInput::make('address2')
                                    ->label('Address 2')
                                    ->maxLength(255),
                                TextInput::make('city')
                                    ->maxLength(255),
                                TextInput::make('state')
                                    ->label('State / region')
                                    ->maxLength(255),
                                TextInput::make('postcode')
                                    ->maxLength(20),
                                Select::make('country')
                                    ->options(fn () => Countries::getNames(app()->getLocale()))
                                    ->searchable()
                                    ->required(),
                            ]),
                    ]),

                Section::make(__('More details'))
                    ->columns(2)
                    ->visible(fn () => ClientFields::forClient()->isNotEmpty())
                    ->schema(fn () => ClientFields::components(ClientFields::forClient(), readOnlyUnlessEditable: true)),

                Section::make(__('Login'))
                    ->schema([
                        Select::make('locale')
                            ->label('Language')
                            ->options(Locales::available())
                            ->placeholder(fn () => __('Default (:language)', ['language' => Locales::available()[$this->getUser()->preferredLocale()]]))
                            ->visible(Locales::multiple()),
                        Toggle::make('panel_notifications')
                            ->label('Notifications')
                            ->helperText(__('The bell at the top of the page: new payment notices, payments received, services that are set up, suspended or about to renew, and our answers to your tickets.'))
                            ->visible(fn () => (bool) config('gaiacp.client_area.notifications')),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ]),
                $this->getPasskeysFormComponent(),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $client = $this->client();

        $data['email'] = $client->email;
        $data['phone'] = $client->phone;
        $data['custom_fields'] = $client->custom_fields ?? [];
        $data['details'] = array_merge(
            $client->only(array_keys(ClientChangeRequests::FIELDS)),
            $this->pendingRequest()?->requestedValues() ?? [],
        );

        return $data;
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $client = $this->client();
        $before = $this->pendingRequest()?->fields;

        $client->update([
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            // Settings → Client fields that clients fill in: saved at once, like email and phone
            'custom_fields' => array_merge($client->custom_fields ?? [], ClientFields::clean(ClientFields::editable(), $data['custom_fields'] ?? [])) ?: null,
        ]);
        $request = app(ClientChangeRequests::class)->request($client, $data['details'] ?? []);
        $this->requested = $request !== null && $request->fields !== $before;

        unset($data['email'], $data['phone'], $data['details'], $data['custom_fields']);

        return parent::handleRecordUpdate($record->refresh(), $data);
    }

    protected function getSavedNotification(): ?Notification
    {
        return $this->requested
            ? Notification::make()->success()->title(__('Saved'))->body(__('We got your changes to your details and will look at them soon.'))
            : parent::getSavedNotification();
    }

    protected function getRedirectUrl(): ?string
    {
        // Reload so a new language shows on the whole page, and the bell comes or goes.
        return $this->getUser()->wasChanged(['locale', 'panel_notifications']) ? static::getUrl() : parent::getRedirectUrl();
    }
}
