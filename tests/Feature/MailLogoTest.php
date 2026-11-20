<?php

namespace Tests\Feature;

use App\Mail\MailLayout;
use App\Support\MailLogo;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Logos in emails get a white outline, so Gmail's and Outlook's dark mode (which never touch images) can't hide them. */
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

    public function test_a_transparent_logo_gets_a_white_outline_and_stays_the_same_inside(): void
    {
        $image = imagecreatefromstring((string) MailLogo::outline($this->logo()));
        $margin = (int) ceil(MailLogo::OUTLINE) + 1;

        // Same size plus the margin for the outline (it is under 160 pixels high, so never enlarged)
        $this->assertSame([400 + 2 * $margin, 100 + 2 * $margin], [imagesx($image), imagesy($image)]);
        // The box stays black, just outside it is opaque white, far from it stays transparent
        $this->assertSame([0, 0, 0, 0], $this->pixel($image, 200 + $margin, 50 + $margin));
        $this->assertSame([255, 255, 255, 0], $this->pixel($image, 99 + $margin, 50 + $margin));
        $this->assertSame([255, 255, 255, 0], $this->pixel($image, 200 + $margin, 71 + $margin));
        $this->assertSame(127, $this->pixel($image, 50, 50)[3]);
        $this->assertSame(127, $this->pixel($image, 0, 0)[3]);
    }

    public function test_a_logo_on_its_own_background_is_used_as_it_is(): void
    {
        $this->assertNull(MailLogo::outline($this->logo(transparent: false)));
        $this->assertNull(MailLogo::outline('not an image'));

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
        $this->assertSame(MailLogo::outline($this->logo()), file_get_contents($copy));
        $this->assertSame($copy, MailLayout::logoPath());
        $this->assertSame('data:image/png;base64,'.base64_encode((string) file_get_contents($copy)), MailLayout::companyLogo(null));
    }

    public function test_the_gaiacp_logo_for_emails_has_its_outline_drawn_in(): void
    {
        $image = imagecreatefrompng(public_path('images/gaiacp/logo-email.png'));

        $this->assertSame([640, 200], [imagesx($image), imagesy($image)]);
        // Left of the G of the wordmark: the white outline, not the transparent background
        $white = 0;

        for ($x = 280; $x < 330; $x++) {
            [$r, $g, $b, $a] = $this->pixel($image, $x, 100);
            $white += (int) ($r > 240 && $g > 240 && $b > 240 && $a < 40);
        }

        $this->assertGreaterThan(0, $white);
    }
}
