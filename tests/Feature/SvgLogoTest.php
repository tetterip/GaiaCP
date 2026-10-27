<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Mail\ClientMail;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use App\Support\SvgLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SvgLogoTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50"><rect width="200" height="50" fill="#1F3D2B"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['gaiacp.company.name' => 'Acme Hosting']);
    }

    private function svgUpload(string $svg, string $name = 'logo.svg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $svg)->mimeType('image/svg+xml');
    }

    public function test_scripts_handlers_and_outside_references_are_removed(): void
    {
        $clean = SvgLogo::clean(<<<'SVG'
            <?xml version="1.0"?>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 40" onload="alert(1)">
              <script>alert(document.cookie)</script>
              <foreignObject width="10" height="10"><iframe src="https://evil.example"></iframe></foreignObject>
              <image xlink:href="https://evil.example/track.png" width="10" height="10"/>
              <a href="javascript:alert(1)"><rect width="100" height="40" fill="green" onclick="alert(2)"/></a>
              <circle cx="20" cy="20" r="10" fill="#fff"/>
            </svg>
            SVG);

        $this->assertNotNull($clean);
        foreach (['<script', 'onload', 'onclick', 'foreignObject', 'iframe', 'evil.example', 'javascript:', '<?xml'] as $unsafe) {
            $this->assertStringNotContainsStringIgnoringCase($unsafe, $clean);
        }
        $this->assertStringContainsString('<circle', $clean);
        $this->assertStringContainsString('fill="green"', $clean);
    }

    public function test_the_size_follows_the_view_box_so_it_scales_in_proportion(): void
    {
        // A viewBox: width and height set from it, replacing 100% and a style that would stretch it
        $clean = SvgLogo::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 100" width="100%" style="width:100%"><rect width="400" height="100"/></svg>');
        $this->assertMatchesRegularExpression('/^<svg[^>]* width="400" height="100"/', $clean);
        $this->assertStringNotContainsString('100%', $clean);

        // Only a width and height in pixels: the viewBox is made from them
        $clean = SvgLogo::clean('<svg xmlns="http://www.w3.org/2000/svg" width="240px" height="60"><rect width="240" height="60"/></svg>');
        $this->assertStringContainsString('viewBox="0 0 240 60"', $clean);
        $this->assertStringContainsString('width="240"', $clean);

        // Nothing that says its shape, or not an SVG at all: refused
        $this->assertNull(SvgLogo::clean('<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"><rect/></svg>'));
        $this->assertNull(SvgLogo::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 50"><rect/></svg>'));
        $this->assertNull(SvgLogo::clean('<html><body>not an svg</body></html>'));
    }

    public function test_an_svg_logo_is_stored_cleaned_and_served_sandboxed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['logo' => $this->svgUpload(str_replace('<rect', '<script>alert(1)</script><rect', self::LOGO))]])
            ->call('save')
            ->assertHasNoFormErrors();

        $file = config('gaiacp.company.logo');
        $this->assertStringEndsWith('.svg', $file);
        $stored = Storage::disk('public')->get($file);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringContainsString('width="200" height="50"', $stored);

        $this->get(Branding::url('logo'))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");

        $this->get('/login')->assertOk()->assertSee(Branding::url('logo'), false);
    }

    public function test_an_svg_without_proportions_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['logo' => $this->svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>')]])
            ->call('save')
            ->assertHasFormErrors(['company.logo']);

        $this->assertNull(config('gaiacp.company.logo'));
    }

    public function test_emails_show_a_png_copy_of_an_svg_logo_or_the_name_when_none_can_be_made(): void
    {
        Storage::disk('public')->put('branding/logo.svg', self::LOGO);
        Settings::save(['company.logo' => 'branding/logo.svg']);

        if (! SvgLogo::canConvert()) {
            $html = ClientMail::sample(MailTemplate::HostingWelcome)->render();
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('Acme Hosting', $html);
        }

        // A copy made earlier (or on a server with Imagick) is what emails embed
        Storage::disk('public')->put('branding/logo.email.png', UploadedFile::fake()->image('logo.png', 640, 160)->getContent());
        $html = ClientMail::sample(MailTemplate::HostingWelcome)->render();
        $this->assertStringContainsString('src="data:image/png;base64,', $html);

        // Replacing the logo deletes the SVG and its copy
        $this->actingAs(User::factory()->create());
        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['logo' => [UploadedFile::fake()->image('new.png', 400, 100)]]])
            ->call('save')
            ->assertHasNoFormErrors();
        Storage::disk('public')->assertMissing(['branding/logo.svg', 'branding/logo.email.png']);
    }

    public function test_imagick_draws_the_email_copy_at_the_size_emails_show(): void
    {
        if (! SvgLogo::canConvert()) {
            $this->markTestSkipped('This PHP has no Imagick with SVG support.');
        }

        Storage::disk('public')->put('branding/logo.svg', SvgLogo::clean(self::LOGO));
        Settings::save(['company.logo' => 'branding/logo.svg']);

        $png = Branding::emailLogoPath();

        $this->assertSame(Storage::disk('public')->path('branding/logo.email.png'), $png);
        $this->assertSame([640, SvgLogo::EMAIL_HEIGHT], array_slice(getimagesize($png), 0, 2));
    }

    public function test_without_imagick_the_browser_draws_the_email_copy_on_the_company_page(): void
    {
        if (SvgLogo::canConvert()) {
            $this->markTestSkipped('This PHP makes the copy itself (Imagick).');
        }

        Storage::disk('public')->put('branding/logo.svg', self::LOGO);
        Settings::save(['company.logo' => 'branding/logo.svg']);
        $this->actingAs(User::factory()->create());
        $png = fn (int $width, int $height) => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('logo.png', $width, $height)->getContent());

        $page = Livewire::test(CompanySettings::class)->assertSeeHtml('storeEmailLogo');

        // Only a PNG no bigger than the email copy is kept
        foreach (['data:image/svg+xml;base64,'.base64_encode(self::LOGO), 'data:image/png;base64,bm90IGEgcG5n', $png(640, 400), $png(4000, 160)] as $wrong) {
            $page->call('storeEmailLogo', $wrong);
            Storage::disk('public')->assertMissing('branding/logo.email.png');
        }

        $page->call('storeEmailLogo', $png(640, 160));
        Storage::disk('public')->assertExists('branding/logo.email.png');
        $this->assertStringContainsString('src="data:image/png;base64,', ClientMail::sample(MailTemplate::HostingWelcome)->render());
        Livewire::test(CompanySettings::class)->assertDontSeeHtml('storeEmailLogo');
    }

    public function test_a_png_logo_needs_no_copy(): void
    {
        Storage::disk('public')->put('branding/logo.png', UploadedFile::fake()->image('logo.png', 400, 100)->getContent());
        Settings::save(['company.logo' => 'branding/logo.png']);
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->assertDontSeeHtml('storeEmailLogo')
            ->call('storeEmailLogo', 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('x.png', 10, 10)->getContent()));

        $this->assertSame(['branding/logo.png'], Storage::disk('public')->files('branding'));
    }
}
