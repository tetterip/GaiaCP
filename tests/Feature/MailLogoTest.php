<?php

namespace Tests\Feature;

use App\Mail\MailLayout;
use App\Support\MailLogo;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Logos in emails sit on a white background drawn into the image, so Gmail's and Outlook's dark mode (which never touch images) can't hide them. */
class MailLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        File::deleteDirectory(storage_path('framework/cache/mail-logos'));
    }

    /** A 400x100 transparent PNG with a black box in its middle, or an opaque one. */
    private function logo(bool $transparent = true): string
    {
        $image = imagecreatetruecolor(400, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 100, 30, 299, 69, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{int, int, int, int} red, green, blue and alpha (0 opaque … 127 transparent) */
    private function pixel(\GdImage $image, int $x, int $y): array
    {
        $rgba = imagecolorat($image, $x, $y);

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, ($rgba >> 24) & 0x7F];
    }

    public function test_a_transparent_logo_is_put_on_a_white_rounded_background(): void
    {
        $image = imagecreatefromstring((string) MailLogo::onBackground($this->logo()));
        $padX = (int) round(100 * MailLogo::PAD_X);
        $padY = (int) round(100 * MailLogo::PAD_Y);

        // Same size plus the space around it (it is under 160 pixels high, so never enlarged)
        $this->assertSame([400 + 2 * $padX, 100 + 2 * $padY], [imagesx($image), imagesy($image)]);
        // The box stays black, the rest is opaque white, the very corners are rounded off (transparent)
        $this->assertSame([0, 0, 0, 0], $this->pixel($image, 200 + $padX, 50 + $padY));
        $this->assertSame([255, 255, 255, 0], $this->pixel($image, 50 + $padX, 50 + $padY));
        $this->assertSame([255, 255, 255, 0], $this->pixel($image, 2, 50));
        $this->assertSame(127, $this->pixel($image, 0, 0)[3]);
        $this->assertSame(127, $this->pixel($image, imagesx($image) - 1, imagesy($image) - 1)[3]);
    }

    public function test_a_logo_on_its_own_background_is_used_as_it_is(): void
    {
        $this->assertNull(MailLogo::onBackground($this->logo(transparent: false)));
        $this->assertNull(MailLogo::onBackground('not an image'));

        Storage::disk('public')->put('branding/logo.png', $this->logo(transparent: false));
        Settings::save(['company.logo' => 'branding/logo.png']);

        $this->assertSame(Storage::disk('public')->path('branding/logo.png'), MailLayout::logoPath());
    }

    public function test_client_emails_embed_the_outlined_copy_made_once(): void
    {
        Storage::disk('public')->put('branding/logo.png', $this->logo());
        Settings::save(['company.logo' => 'branding/logo.png']);
        $original = Storage::disk('public')->path('branding/logo.png');

        $copy = MailLayout::logoPath();

        $this->assertSame(MailLogo::copyPath($original), $copy);
        $this->assertFileExists($copy);
        $this->assertSame(MailLogo::onBackground($this->logo()), file_get_contents($copy));
        $this->assertSame($copy, MailLayout::logoPath());
        $this->assertSame('data:image/png;base64,'.base64_encode((string) file_get_contents($copy)), MailLayout::companyLogo(null));
    }

    public function test_the_gaiacp_logo_for_emails_has_its_background_drawn_in(): void
    {
        $image = imagecreatefrompng(public_path('images/gaiacp/logo-email.png'));

        // Drawn by MailLogo from the 640x200 logo: 160 high plus the space around it, shown 47px high (the logo 40px)
        $this->assertSame([560, 188], [imagesx($image), imagesy($image)]);
        $this->assertSame([255, 255, 255, 0], $this->pixel($image, 10, 94));
        $this->assertSame(127, $this->pixel($image, 0, 0)[3]);
    }
}
