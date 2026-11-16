<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bank plugins can't share code (a plugin never uses another plugin), so each carries its own copy of the Cardlink
 * protocol and of the form that posts the client to the bank. A fix made in one copy has to reach the others: this fails
 * until it does. Only comments, the namespace and the bank's own addresses may differ.
 */
class BankPluginCopiesTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function copies(): array
    {
        return [
            'Cardlink' => ['Cardlink.php', ['AlphaBank', 'Eurobank']],
            'AutoPostForm' => ['AutoPostForm.php', ['AlphaBank', 'Eurobank', 'PiraeusBank']],
        ];
    }

    /** @param  list<string>  $plugins */
    #[DataProvider('copies')]
    public function test_the_copies_of_a_shared_file_are_the_same_code(string $file, array $plugins): void
    {
        $first = array_shift($plugins);
        $expected = $this->code($first, $file);

        $this->assertNotSame('', $expected);

        foreach ($plugins as $plugin) {
            $this->assertSame($expected, $this->code($plugin, $file), "plugins/{$plugin}/{$file} differs from plugins/{$first}/{$file}: make the same change in both.");
        }
    }

    /** The file's code without comments and whitespace, its namespace and the bank's addresses made the same. */
    private function code(string $plugin, string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 2)."/plugins/{$plugin}/{$file}")) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $code .= (is_array($token) ? $token[1] : $token).' ';
        }

        $code = str_replace("Plugins\\{$plugin}", 'Plugins\\Bank', $code);

        return (string) preg_replace("/(LIVE_URL|TEST_URL) = 'https:[^']+'/", "$1 = 'https://bank'", $code);
    }
}
