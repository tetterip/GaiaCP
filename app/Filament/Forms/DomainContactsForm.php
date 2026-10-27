<?php

namespace App\Filament\Forms;

use App\Models\Client;
use App\Provisioning\DomainContact;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\Intl\Countries;

/**
 * A domain's contacts at its registrar, a tab per role, under `contacts.<role>.<field>` of the form's state; used by
 * the admin's Registrar menu and the client's domain page. Each tab can take the client's details, and every tab but
 * the owner's can copy the owner's.
 */
class DomainContactsForm
{
    /**
     * @param  list<string>  $roles  the ones the registrar has
     */
    public static function tabs(array $roles, Client $client, string $accountLabel): Tabs
    {
        return Tabs::make('contacts')
            ->tabs(collect($roles)->map(fn (string $role) => Tab::make(DomainContact::label($role))
                ->schema([
                    Actions::make(array_values(array_filter([
                        Action::make("account_{$role}")
                            ->label($accountLabel)
                            ->link()
                            ->action(fn (Set $set) => self::fill($set, $role, DomainContact::fromClient($client)->toArray())),
                        $role === 'registrant' ? null : Action::make("owner_{$role}")
                            ->label(__('Same as the owner'))
                            ->link()
                            ->action(fn (Get $get, Set $set) => self::fill($set, $role, (array) $get('contacts.registrant'))),
                    ])))->key("contactActions_{$role}"),
                    Grid::make(['default' => 1, 'sm' => 2])->schema(self::fields($role)),
                ]))->all());
    }

    /**
     * @param  array<string, DomainContact>  $contacts
     * @return array{contacts: array<string, array<string, string>>}
     */
    public static function state(array $contacts): array
    {
        return ['contacts' => array_map(fn (DomainContact $contact) => $contact->toArray(), $contacts)];
    }

    /**
     * @param  array<string, mixed>  $data  the form's state
     * @param  list<string>  $roles
     * @return array<string, DomainContact>
     */
    public static function contacts(array $data, array $roles): array
    {
        return collect($roles)->mapWithKeys(fn (string $role) => [$role => DomainContact::fromArray((array) ($data['contacts'][$role] ?? []))])->all();
    }

    /** @param  array<string, mixed>  $values */
    private static function fill(Set $set, string $role, array $values): void
    {
        foreach (DomainContact::FIELDS as $field) {
            $set("contacts.{$role}.{$field}", $values[$field] ?? null);
        }
    }

    /** @return list<Field> */
    private static function fields(string $role): array
    {
        $path = "contacts.{$role}";

        return [
            TextInput::make("{$path}.first_name")->label('First name')->required()->maxLength(100),
            TextInput::make("{$path}.last_name")->label('Last name')->required()->maxLength(100),
            TextInput::make("{$path}.company")->label('Company')->maxLength(255)->columnSpanFull(),
            TextInput::make("{$path}.address1")->label('Address 1')->required()->maxLength(255),
            TextInput::make("{$path}.address2")->label('Address 2')->maxLength(255),
            TextInput::make("{$path}.city")->label('City')->required()->maxLength(100),
            TextInput::make("{$path}.state")->label('State / region')->required()->maxLength(100),
            TextInput::make("{$path}.postcode")->label('Postcode')->required()->maxLength(20),
            Select::make("{$path}.country")->label('Country')->options(fn () => Countries::getNames(app()->getLocale()))->searchable()->required(),
            TextInput::make("{$path}.phone")->label('Phone number')->tel()->required()->maxLength(50)
                ->helperText(__('With country code, e.g. +30 6912345678.')),
            TextInput::make("{$path}.email")->label('Email address')->email()->required()->maxLength(255),
        ];
    }
}
