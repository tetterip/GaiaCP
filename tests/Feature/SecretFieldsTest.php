<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** Secret fields turn off the browser's password autofill, so a saved login never lands in an API token or password field. */
class SecretFieldsTest extends TestCase
{
    public function test_every_password_field_turns_off_autofill(): void
    {
        $files = Finder::create()->files()->name('*.php')->in([app_path('Filament'), base_path('plugins')]);
        $missing = [];

        foreach ($files as $file) {
            // Each field is one chain of calls: from TextInput::make( up to the next one.
            foreach (preg_split('/(?=TextInput::make\()/', $file->getContents()) as $field) {
                if (str_contains($field, '->password()') && ! str_contains($field, '->autocomplete(')) {
                    preg_match("/TextInput::make\\('([^']+)'/", $field, $name);
                    $missing[] = $file->getRelativePathname().': '.($name[1] ?? '?');
                }
            }
        }

        $this->assertSame([], $missing, 'Add ->autocomplete(\'new-password\') to these password fields.');
    }
}
