<?php

namespace Tests\Support\TestRegistrar;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;

/** Registers Tests\Support\TestRegistrar as the registrar module `test`, for the tests that use Tests\FakesRegistrar. */
class TestRegistrarPlugin extends Plugin implements RegistrarModule
{
    public function __construct(private object $account) {}

    public function id(): string
    {
        return 'test-registrar';
    }

    public function name(): string
    {
        return 'Test registrar';
    }

    public function description(): string
    {
        return 'The registrar GaiaCP\'s own tests use.';
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test registrar';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new TestRegistrar($this->account, $credentials);
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.username')->label('Username')->required(),
            TextInput::make('credentials.password')
                ->label('Password')
                ->password()
                ->required(fn (string $operation) => $operation === 'create')
                ->formatStateUsing(fn () => null), // not loaded into the page; a blank field keeps the stored one
        ];
    }
}
