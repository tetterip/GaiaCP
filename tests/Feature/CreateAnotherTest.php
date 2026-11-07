<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * "Create & create another" is left out of the full create pages (after creating, the admin opens what they made)
 * and kept in the small Settings windows, where several are often added in a row.
 */
class CreateAnotherTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_create_page_offers_create_another(): void
    {
        $pages = [];
        foreach (Finder::create()->files()->in(app_path('Filament'))->name('Create*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR));
            if (is_subclass_of($class, CreateRecord::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $pages[] = $class;
                $this->assertFalse((new ReflectionClass($class))->getStaticPropertyValue('canCreateAnother'), "$class offers Create & create another");
            }
        }

        $this->assertGreaterThan(10, count($pages));
    }

    public function test_settings_windows_keep_it_except_currencies(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->assertFalse(Livewire::test(ManageCurrencies::class)->instance()->getAction('create')->canCreateAnother());
        $this->assertTrue(Livewire::test(ManageTlds::class)->instance()->getAction('create')->canCreateAnother());
    }
}
