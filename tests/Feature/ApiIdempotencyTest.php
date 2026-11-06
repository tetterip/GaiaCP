<?php

namespace Tests\Feature;

use App\Api\OpenApi;
use App\Enums\ServiceStatus;
use App\Http\Middleware\IdempotentApiRequest;
use App\Models\ApiKey;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** A REST API POST sent again with the same Idempotency-Key (a retry after a timeout) is done once. */
class ApiIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @param  list<string>|null  $permissions  null = all of them */
    private function key(?array $permissions = null): string
    {
        [, $token] = ApiKey::generate(['name' => 'Bank import', 'permissions' => $permissions ?? OpenApi::permissions()]);

        return $token;
    }

    private function api(string $token, ?string $idempotencyKey = null): static
    {
        return $this->flushHeaders()->withHeaders(array_filter([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Idempotency-Key' => $idempotencyKey,
        ]));
    }

    private function service(): Service
    {
        return Service::factory()->create(['start_date' => '2025-11-01', 'next_due_date' => '2026-11-01', 'price_cents' => 6000, 'billing_cycle' => 'annually', 'status' => ServiceStatus::Active]);
    }

    public function test_a_payment_sent_again_with_the_same_key_is_recorded_once(): void
    {
        $service = $this->service();
        $token = $this->key();
        $body = ['service_id' => $service->id, 'method' => 'cash', 'reference' => 'TX-1'];

        $first = $this->api($token, 'bank-TX-1')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $again = $this->api($token, 'bank-TX-1')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('2027-11-01', $service->refresh()->next_due_date->toDateString(), 'one cycle, not two');

        // Without the header (or with a new key) it is a new payment, as before
        $this->api($token, 'bank-TX-2')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->api($token)->postJson('/api/v1/payments', $body)->assertCreated();
        $this->assertSame(3, Payment::query()->count());
    }

    public function test_the_same_key_with_another_call_is_refused(): void
    {
        $service = $this->service();
        $token = $this->key();

        $this->api($token, 'k-1')->postJson('/api/v1/payments', ['service_id' => $service->id, 'method' => 'cash'])->assertCreated();
        $this->api($token, 'k-1')->postJson('/api/v1/payments', ['service_id' => $service->id, 'method' => 'cash', 'amount_cents' => 100])
            ->assertUnprocessable()->assertJson(['message' => 'This Idempotency-Key was already used for another call: use a new key for each new call.']);

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_call_still_running_with_the_key_is_answered_409(): void
    {
        $service = $this->service();
        $token = $this->key();
        $body = ['service_id' => $service->id, 'method' => 'cash'];

        // The first call holds the key but has not answered yet
        DB::table('api_idempotency_keys')->insert([
            'api_key_id' => ApiKey::query()->sole()->id,
            'idempotency_key' => 'k-1',
            'fingerprint' => hash('sha256', "POST\napi/v1/payments\n".json_encode($body)),
            'created_at' => now(),
        ]);

        $this->api($token, 'k-1')->postJson('/api/v1/payments', $body)->assertConflict()->assertHeader('Retry-After', '1');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_keys_belong_to_their_api_key_and_last_a_day(): void
    {
        $service = $this->service();
        $body = ['service_id' => $service->id, 'method' => 'cash'];
        $website = $this->key();
        $bank = $this->key();

        $this->api($website, 'same')->postJson('/api/v1/payments', $body)->assertCreated();
        $this->api($bank, 'same')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, Payment::query()->count());

        $this->travel(IdempotentApiRequest::KEEP_HOURS + 1)->hours();
        $this->api($website, 'same')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(3, Payment::query()->count());

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertSame(1, DB::table('api_idempotency_keys')->count(), 'only the one from just now is kept');
    }

    public function test_refused_and_failed_answers_are_not_kept_so_a_retry_can_succeed(): void
    {
        $service = $this->service();
        $token = $this->key(['services.read']);
        $body = ['service_id' => $service->id, 'method' => 'cash'];

        $this->api($token, 'k-1')->postJson('/api/v1/payments', $body)->assertForbidden();
        $key = ApiKey::query()->sole();
        $key->update(['permissions' => OpenApi::permissions()]);

        $this->api($token, 'k-1')->postJson('/api/v1/payments', $body)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        // A validation error is kept: the same wrong call gets the same answer
        $this->api($token, 'k-2')->postJson('/api/v1/payments', ['service_id' => 0])->assertUnprocessable();
        $this->api($token, 'k-2')->postJson('/api/v1/payments', ['service_id' => 0])->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_reads_and_long_keys(): void
    {
        $token = $this->key();

        $this->api($token, 'k-1')->getJson('/api/v1/payments')->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(0, DB::table('api_idempotency_keys')->count(), 'only POSTs');

        $this->api($token, str_repeat('x', 256))->postJson('/api/v1/payments', [])->assertUnprocessable()
            ->assertJson(['message' => 'The Idempotency-Key header may be at most 255 characters.']);
    }
}
