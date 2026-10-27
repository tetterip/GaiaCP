<?php

namespace Tests\Feature\Provisioning;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function domains(): array
    {
        return [
            'simple' => ['example.gr', 'example'],
            'www and hyphen' => ['www.my-shop.com', 'myshop'],
            'leading digits' => ['24news.gr', 'news'],
            'all digits' => ['123.gr', 'u'],
            'starts with test' => ['testsite.com', 'utestsite'],
            'too long' => ['averyveryverylongdomainname.eu', 'averyveryverylon'],
            'uppercase' => ['ACME.GR', 'acme'],
        ];
    }

    #[DataProvider('domains')]
    public function test_base(string $domain, string $expected): void
    {
        $this->assertSame($expected, (new UsernameGenerator)->base($domain));
    }

    public function test_avoids_usernames_taken_on_the_same_server(): void
    {
        $server = Server::factory()->create();
        Service::factory()->for($server)->create(['username' => 'averyveryverylon']);
        Service::factory()->create(['username' => 'example']); // other server: fine to reuse

        $long = Service::factory()->for($server)->make(['domain' => 'averyveryverylongdomainname.eu']);
        $example = Service::factory()->for($server)->make(['domain' => 'example.gr']);

        $this->assertSame('averyveryverylo1', (new UsernameGenerator)->forService($long));
        $this->assertSame('example', (new UsernameGenerator)->forService($example));
    }
}
