<?php

namespace Tests\Feature\Provisioning;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Cpanel\WhmClient;
use Tests\TestCase;

class WhmClientTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create(['hostname' => 'whm.example.com', 'port' => 2087, 'username' => 'root', 'api_token' => 'TOKEN123', ...$attributes]);
    }

    public function test_sends_authenticated_form_post_to_api_1(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['version' => '11.130']])]);

        $data = (new WhmClient($this->server()))->call('createacct', ['username' => 'acme', 'password' => 's3cret']);

        $this->assertSame(['version' => '11.130'], $data);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://whm.example.com:2087/json-api/createacct?api.version=1'
                && $request->header('Authorization')[0] === 'whm root:TOKEN123'
                && $request['username'] === 'acme'
                && ! str_contains($request->url(), 's3cret');
        });
    }

    public function test_api_error_reason_becomes_the_exception_message(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Sorry, a group for that username already exists.']])]);

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('WHM createacct: Sorry, a group for that username already exists.');

        (new WhmClient($this->server()))->call('createacct');
    }

    public function test_rejected_token(): void
    {
        Http::fake(['*' => Http::response('Access denied', 403)]);

        $this->expectExceptionMessage('WHM rejected the API token for whm.example.com (HTTP 403).');

        (new WhmClient($this->server()))->call('version');
    }

    public function test_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Cannot reach whm.example.com');

        (new WhmClient($this->server()))->call('version');
    }

    public function test_non_json_response(): void
    {
        Http::fake(['*' => Http::response('<html>login</html>')]);

        $this->expectExceptionMessage('WHM version returned an unexpected response.');

        (new WhmClient($this->server()))->call('version');
    }

    public function test_token_never_appears_in_exception_messages(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'nope']])]);

        try {
            (new WhmClient($this->server()))->call('version');
            $this->fail('no exception');
        } catch (ProvisioningException $e) {
            $this->assertStringNotContainsString('TOKEN123', $e->getMessage());
        }
    }
}
