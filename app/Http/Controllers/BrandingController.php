<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use App\Support\SvgLogo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The company's logo and favicon for the client area's login page, menu and browser tab, and payment notices (public, cached). */
class BrandingController extends Controller
{
    public function __invoke(string $asset): BinaryFileResponse
    {
        $path = Branding::path($asset);

        abort_if($path === null, 404);

        // The URL carries a version (Branding::url()), so a new upload is a new URL
        $headers = ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff'];

        if (SvgLogo::isSvg($path)) {
            // Cleaned on upload already; opened on its own, it still can't run anything or load anything
            $headers['Content-Type'] = 'image/svg+xml';
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }

        return response()->file($path, $headers);
    }
}
