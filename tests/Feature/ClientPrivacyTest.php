<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\AdminArea;
use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Privacy\AnonymizeClient;
use App\Privacy\ClientDataExport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** A client's personal data on request (GDPR): Personal data → Download their data / Anonymize. */
class ClientPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        $this->client = Client::factory()->create(['first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'email' => 'nikos@example.com', 'phone' => '+30.6900000000', 'notes' => 'VIP']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => 'nikos@example.com', 'super_admin' => false]);
        $this->admin = User::factory()->create(['super_admin' => true]);
    }

    private function ticket(): Ticket
    {
        $ticket = app(Tickets::class)->open($this->client, $this->user, 'Email not working', 'I cannot send email.',
            files: [UploadedFile::fake()->create('error.log', 3)->store('tickets', 'local') => 'error.log']);
        app(Tickets::class)->reply($ticket, $this->admin, 'He is a difficult one.', internal: true);

        return $ticket;
    }

    public function test_the_export_holds_the_clients_records_and_files_but_no_secrets(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'password' => 's3cret-pass', 'domain' => 'nikos.gr']);
        $this->ticket();
        EmailLog::create(['client_id' => $this->client->id, 'to' => 'nikos@example.com', 'subject' => 'Welcome', 'body' => 'Hello Νίκος', 'status' => 'sent', 'to_client' => true]);

        $export = new ClientDataExport($this->client);
        $path = $export->zip();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $client = json_decode($zip->getFromName('client.json'), true);
        $services = json_decode($zip->getFromName('services.json'), true);
        $messages = json_decode($zip->getFromName('ticket-messages.json'), true);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        File::delete($path);

        $this->assertSame('Νίκος', $client[0]['first_name']);
        $this->assertSame('nikos.gr', $services[0]['domain']);
        $this->assertSame($service->id, $services[0]['id']);
        $this->assertArrayNotHasKey('password', $services[0]);
        $this->assertSame(['I cannot send email.'], array_column($messages, 'body'), 'internal notes stay out');
        $this->assertStringContainsString('Hello Νίκος', $all);
        $this->assertStringNotContainsString('s3cret-pass', $all);
        $this->assertStringNotContainsString('difficult', $all);
        $this->assertContains('README.txt', $names);
        $this->assertCount(1, array_filter($names, fn ($name) => str_starts_with($name, 'ticket-files/')));
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.data_exported', 'client_id' => $this->client->id]);
    }

    public function test_a_client_with_running_services_unpaid_notices_or_credit_is_not_anonymized(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'status' => ServiceStatus::Active]);
        $this->assertCount(1, AnonymizeClient::blockers($this->client));

        $service->update(['status' => ServiceStatus::Terminated]);
        $this->assertSame([], AnonymizeClient::blockers($this->client));

        $this->client->forceFill(['credit_cents' => 500])->save();
        $this->assertCount(1, AnonymizeClient::blockers($this->client->fresh()));

        $this->expectException(\RuntimeException::class);
        app(AnonymizeClient::class)($this->client->fresh());
    }

    public function test_anonymizing_removes_the_personal_data_and_keeps_the_billing(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'status' => ServiceStatus::Terminated, 'password' => 'pw']);
        $invoice = Invoice::query()->create(['client_id' => $this->client->id, 'number' => 'PF-2026-0001', 'status' => 'paid', 'date' => now(), 'due_date' => now(), 'currency' => 'EUR',
            'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000, 'bill_to' => ['name' => 'Νίκος Παπαδόπουλος']]);
        $ticket = $this->ticket();
        $file = $ticket->messages()->first()->attachments()->sole()->path;
        EmailLog::create(['client_id' => $this->client->id, 'to' => 'nikos@example.com', 'subject' => 'Welcome', 'body' => 'Hello', 'status' => 'sent', 'to_client' => true]);
        ActivityLog::record('client.updated', 'Νίκος Παπαδόπουλος changed their email to nikos@example.com', client: $this->client, properties: ['email' => 'nikos@example.com']);

        app(AnonymizeClient::class)($this->client);
        $client = $this->client->fresh();

        $this->assertSame("Anonymized #{$client->id}", $client->name);
        $this->assertSame("client-{$client->id}@anonymized.invalid", $client->email);
        $this->assertNull($client->phone);
        $this->assertNull($client->notes);
        $this->assertSame(ClientStatus::Closed, $client->status);
        $this->assertTrue(AnonymizeClient::done($client));

        $this->assertModelMissing($this->user);
        $this->assertModelMissing($ticket);
        Storage::disk('local')->assertMissing($file);
        $this->assertSame(0, EmailLog::query()->where('client_id', $client->id)->count());
        $this->assertNull(DB::table('services')->where('id', $service->id)->value('password'));

        $this->assertSame('Νίκος Παπαδόπουλος', $invoice->fresh()->bill_to['name'], 'payment notices keep who they were issued to');
        $log = ActivityLog::query()->where('event', 'client.updated')->sole();
        $this->assertSame('[removed] changed their email to [removed]', $log->description);
        $this->assertStringNotContainsString('nikos@', json_encode($log->properties));
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.anonymized', 'client_id' => $client->id]);
    }

    public function test_only_super_admins_see_the_menu_and_the_email_must_be_typed(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        Livewire::test(EditClient::class, ['record' => $this->client->id])->assertActionHidden('anonymizeClient');

        $this->actingAs($this->admin);
        Livewire::test(EditClient::class, ['record' => $this->client->id])
            ->callAction('exportClientData')
            ->assertFileDownloaded("client-{$this->client->id}-data-".now()->format('Y-m-d').'.zip');
        $this->assertSame([], File::glob(storage_path('app/private/exports/*.zip')), 'the zip is deleted once sent');

        Livewire::test(EditClient::class, ['record' => $this->client->id])
            ->callAction('anonymizeClient', ['confirm' => 'someone@else.com'])
            ->assertHasActionErrors(['confirm']);
        $this->assertFalse(AnonymizeClient::done($this->client->fresh()));

        Livewire::test(EditClient::class, ['record' => $this->client->id])
            ->callAction('anonymizeClient', ['confirm' => 'nikos@example.com'])
            ->assertHasNoActionErrors()
            ->assertNotified('Client anonymized');

        $this->assertTrue(AnonymizeClient::done($this->client->fresh()));
    }
}
