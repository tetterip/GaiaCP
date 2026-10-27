<?php

namespace Tests\Feature;

use App\Actions\UpgradeRequests;
use App\Enums\ChangeRequestStatus;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\UpgradeRequests\Pages\ListUpgradeRequests;
use App\Mail\ClientMail;
use App\Mail\UpgradeRequestMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\UpgradeRequest;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** A client asks to move a service to another package from the client area; an admin approves or declines it. */
class UpgradeRequestsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    private Product $pro;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeWhm();
        $this->travelTo('2026-10-23');

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $basic = Product::factory()->create(['name' => 'Hosting Basic']);
        $this->pro = Product::factory()->priced(12000)->create(['name' => 'Hosting Pro', 'package' => 'pro']);
        Product::factory()->priced(9000)->create(['name' => 'Hidden plan', 'hidden' => true]);
        $this->hosting = Service::factory()->for($this->client)->create([
            'product_id' => $basic->id, 'label' => 'Hosting Basic', 'username' => 'maria', 'domain' => 'maria.gr',
            'start_date' => '2025-12-01', 'next_due_date' => '2026-12-01',
        ]);
        $this->whmAccounts['maria'] = false;
    }

    private function clientPage(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()]);
    }

    private function adminList(): Testable
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        return Livewire::test(ListUpgradeRequests::class);
    }

    public function test_the_client_asks_and_the_admin_approves_with_the_difference_now(): void
    {
        $this->assertSame(['Hosting Pro'], UpgradeRequests::products($this->hosting)->pluck('name')->all(), 'packages clients can order only');

        $this->clientPage()
            ->callAction('requestUpgrade', ['product_id' => $this->pro->id, 'notes' => 'More space please'])
            ->assertHasNoFormErrors()
            ->assertSee('Package change requested');

        $request = UpgradeRequest::query()->sole();
        $this->assertSame([12000, 'More space please'], [$request->price_cents, $request->notes]);
        Mail::assertSent(UpgradeRequestMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        $this->assertSame(1, $this->admin->notifications()->count(), 'the bell rings');
        $this->assertFalse(UpgradeRequests::canRequest($this->hosting->fresh()), 'one request at a time');

        $this->adminList()
            ->callAction(TestAction::make('approve')->table($request), ['price' => '120.00', 'charge_now' => true])
            ->assertHasNoFormErrors();

        $service = $this->hosting->fresh();
        $this->assertSame([$this->pro->id, 'pro', 12000], [$service->product_id, $service->package, $service->price_cents]);
        $this->assertSame(ChangeRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(641, Invoice::query()->sole()->total_cents, '39 days of 60.00 more a year');
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::UpgradeApproved && $mail->values['new_price'] === '€120.00');
    }

    public function test_the_admin_declines_and_nothing_changes(): void
    {
        $request = app(UpgradeRequests::class)->request($this->hosting, $this->pro);

        $this->adminList()->callAction(TestAction::make('decline')->table($request));

        $this->assertSame(ChangeRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('basic', $this->hosting->fresh()->package);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::UpgradeRejected);
        $this->assertTrue(UpgradeRequests::canRequest($this->hosting->fresh()), 'may ask again');
    }

    public function test_the_client_withdraws_it(): void
    {
        app(UpgradeRequests::class)->request($this->hosting, $this->pro);

        $this->clientPage()->callAction('withdrawUpgrade');

        $this->assertSame(0, UpgradeRequest::query()->count());
    }

    public function test_a_hidden_product_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(UpgradeRequests::class)->request($this->hosting, Product::query()->where('name', 'Hidden plan')->sole());
    }
}
