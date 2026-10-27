<?php

namespace Database\Factories;

use App\Models\Registrar;
use App\Models\Tld;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registrar>
 */
class RegistrarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Easy.gr',
            'module' => 'easygr',
            'credentials' => ['username' => 'reseller', 'password' => 'secret'],
            'verify_tls' => true,
            'active' => true,
            'is_default' => true,
        ];
    }

    /**
     * Priced TLDs (Settings → Domain pricing) with this registrar as the preferred one.
     *
     * @param  list<string>  $tlds
     */
    public function withTlds(array $tlds = ['.gr', '.com', '.com.gr']): static
    {
        return $this->afterCreating(fn (Registrar $registrar) => array_map(
            fn (string $tld) => Tld::query()->updateOrCreate(['tld' => $tld], ['registrar_id' => $registrar->id, 'register_1y_cents' => 1500, 'renew_1y_cents' => 1500]),
            $tlds,
        ));
    }
}
