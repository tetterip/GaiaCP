<?php

namespace Tests\Feature\Provisioning;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Plesk\PleskClient;
use Tests\FakesPlesk;
use Tests\TestCase;

class PleskClientTest extends TestCase
{
    use FakesPlesk, RefreshDatabase;

    private function server(array $attributes = []): Server
    {
        return Server::factory()->plesk()->create(['hostname' => 'plesk.example.com', 'port' => 8443, 'username' => 'admin', 'api_token' => 'Pa55', ...$attributes]);
    }

    public function test_builds_ordered_xml_with_escaping_repeats_and_empty_elements(): void
    {
        $xml = (new PleskClient($this->server()))->packet('webspace', 'add', [
            'gen_setup' => ['name' => 'a&b.gr', 'owner-id' => 7, 'skip' => null],
            'hosting' => ['vrt_hst' => ['property' => [['name' => 'ftp_login', 'value' => 'u'], ['name' => 'ftp_password', 'value' => '<p>']]]],
            'filter' => [],
        ]);

        $this->assertStringContainsString(
            '<packet><webspace><add><gen_setup><name>a&amp;b.gr</name><owner-id>7</owner-id></gen_setup>'
            .'<hosting><vrt_hst><property><name>ftp_login</name><value>u</value></property>'
            .'<property><name>ftp_password</name><value>&lt;p&gt;</value></property></vrt_hst></hosting><filter/></add></webspace></packet>',
            $xml,
        );
    }

    public function test_authenticates_with_login_and_password(): void
    {
        $this->fakePlesk();

        (new PleskClient($this->server()))->call('server', 'get', ['stat' => []]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://plesk.example.com:8443/enterprise/control/agent.php'
            && $r->header('HTTP_AUTH_LOGIN')[0] === 'admin'
            && $r->header('HTTP_AUTH_PASSWD')[0] === 'Pa55'
            && ! $r->hasHeader('KEY')
            && str_starts_with($r->header('Content-Type')[0], 'text/xml'));
    }

    public function test_authenticates_with_a_secret_key_when_there_is_no_login(): void
    {
        $this->fakePlesk();

        (new PleskClient($this->server(['username' => null, 'api_token' => 'secret-key'])))->call('server', 'get', ['stat' => []]);

        Http::assertSent(fn (Request $r) => $r->header('KEY')[0] === 'secret-key' && ! $r->hasHeader('HTTP_AUTH_LOGIN'));
    }

    public function test_system_error(): void
    {
        $this->fakePlesk();
        $this->pleskRejectAuth = true;

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Plesk: Authentication failed (error 1001)');

        (new PleskClient($this->server()))->call('server', 'get', ['stat' => []]);
    }

    public function test_operation_error_and_not_found(): void
    {
        $this->fakePlesk();
        $client = new PleskClient($this->server());

        $this->assertSame([], $client->call('webspace', 'get', ['filter' => ['name' => 'none.gr']], allowNotFound: true));

        $this->expectExceptionMessage('Plesk webspace get: Object not found. (error 1013)');
        $client->call('webspace', 'get', ['filter' => ['name' => 'none.gr']]);
    }

    public function test_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect (see https://curl.se/libcurl/c/libcurl-errors.html) for https://x'));

        try {
            (new PleskClient($this->server()))->call('server', 'get');
            $this->fail('no exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('Cannot reach plesk.example.com:8443: cURL error 7: Failed to connect', $e->getMessage());
        }
    }

    public function test_non_xml_response(): void
    {
        Http::fake(['*' => Http::response('<html>not plesk</html>')]);
        $this->expectExceptionMessage('Plesk returned an unexpected response.');
        (new PleskClient($this->server()))->call('server', 'get');
    }
}
