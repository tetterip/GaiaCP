<?php

namespace Tests\Feature;

use App\Enums\DownloadVisibility;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Downloads;
use App\Filament\Resources\Downloads\DownloadResource;
use App\Filament\Resources\Downloads\Pages\ManageDownloads;
use App\Models\Client;
use App\Models\Download;
use App\Models\DownloadCategory;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Support → Downloads: files for everyone, clients, or the clients of some products, handed out only to them. */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private function file(string $title, DownloadVisibility $visibility, array $products = [], array $extra = []): Download
    {
        $path = Download::FOLDER.'/'.str_repeat('a', 39).fake()->randomLetter().'.pdf';
        Storage::disk('local')->put($path, 'contents of '.$title);

        return Download::query()->create([
            'texts' => ['en' => ['title' => $title, 'description' => 'About '.$title]],
            'path' => $path,
            'file_name' => strtolower($title).'.pdf',
            'size' => 2048,
            'visibility' => $visibility,
            'product_ids' => $products,
            ...$extra,
        ]);
    }

    private function signInClient(): Client
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');

        return $client;
    }

    public function test_each_sees_and_downloads_only_their_files(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel('client');
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $public = $this->file('Brochure', DownloadVisibility::Everyone, extra: ['download_category_id' => DownloadCategory::create(['names' => ['en' => 'Guides']])->id]);
        $clients = $this->file('Guide', DownloadVisibility::Clients);
        $mine = $this->file('Plugin', DownloadVisibility::Products, [(string) $product->id]);
        $theirs = $this->file('Other', DownloadVisibility::Products, [$other->id]);
        $hidden = $this->file('Draft', DownloadVisibility::Everyone, extra: ['published' => false]);

        // A visitor: only the file for everyone
        $this->get(Downloads::getUrl())->assertOk()->assertSee('Guides')->assertSee('Brochure')->assertSee('About Brochure')->assertDontSee('Guide<')->assertDontSee('Plugin');
        $this->get(route('filament.client.download-file', $public->id))->assertOk()->assertDownload('brochure.pdf');
        $this->get(route('filament.client.download-file', $clients->id))->assertNotFound();
        $this->get(route('filament.client.download-file', $hidden->id))->assertNotFound();
        $this->assertSame(1, $public->fresh()->download_count);

        // A client: theirs and their products', once the service is active
        $client = $this->signInClient();
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'status' => ServiceStatus::Pending]);
        $this->get(route('filament.client.download-file', $mine->id))->assertNotFound();
        $service->update(['status' => ServiceStatus::Active]);

        $this->get(Downloads::getUrl())->assertOk()->assertSee('Brochure')->assertSee('Guide')->assertSee('Plugin')->assertDontSee('About Other')->assertDontSee('About Draft');
        $this->get(route('filament.client.download-file', $mine->id))->assertOk()->assertDownload('plugin.pdf');
        $this->get(route('filament.client.download-file', $theirs->id))->assertNotFound();
    }

    public function test_the_page_is_hidden_without_files_and_deleting_one_deletes_its_file(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel('client');
        $this->get(Downloads::getUrl())->assertForbidden();

        $file = $this->file('Guide', DownloadVisibility::Clients);
        $this->get(Downloads::getUrl())->assertForbidden(); // visitors see only files for everyone
        Storage::disk('local')->assertExists($file->path);
        $file->delete();
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_admins_upload_a_file_and_a_forged_path_is_refused(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/secret.zip', 'secret');
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageDownloads::class)
            ->callAction('create', [
                'path' => UploadedFile::fake()->createWithContent('manual.pdf', '%PDF-1.4 a manual'),
                'visibility' => 'clients',
                'texts' => ['en' => ['title' => 'Manual']],
            ])
            ->assertHasNoActionErrors();

        $download = Download::query()->sole();
        $this->assertSame('manual.pdf', $download->file_name);
        $this->assertMatchesRegularExpression('~^downloads/[A-Za-z0-9]{40}\.pdf$~', $download->path);
        $this->assertGreaterThan(0, $download->size);

        $this->expectException(ValidationException::class);
        DownloadResource::checkFile(['path' => 'downloads/../backups/secret.zip', 'file_name' => 'x.zip']);
    }
}
