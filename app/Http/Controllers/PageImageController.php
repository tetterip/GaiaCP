<?php

namespace App\Http\Controllers;

use App\Support\PageImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** An image from a page's text (App\Support\PageImages), for everyone: pages for clients only hold nothing secret in their pictures' random names. */
class PageImageController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = PageImages::path($file);

        abort_if($path === null, 404);

        // A new image is a new random name, so the old one can be kept for good
        return response()->file($path, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
