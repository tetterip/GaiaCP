<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The admin panel as an app (PWA): its manifest, so a phone or computer can install it on the home screen, and its
 * service worker, which shows the push notifications (App\Push\WebPush) and opens their page when tapped. Both are
 * under /admin so the app's scope is the admin panel only; they work in a subfolder install too.
 */
class PwaController extends Controller
{
    public const THEME_COLOR = '#358C8C';

    public function manifest(): JsonResponse
    {
        $icon = fn (string $file, string $size, string $purpose = 'any') => [
            'src' => asset("images/gaiacp/{$file}"), 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose,
        ];

        return response()->json([
            'id' => url('/admin'),
            'name' => 'GaiaCP',
            'short_name' => 'GaiaCP',
            'start_url' => url('/admin'),
            'scope' => url('/admin').'/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                $icon('icon-192.png', '192x192'),
                $icon('icon-512.png', '512x512'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
            ],
        ], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json');
    }

    public function serviceWorker(): Response
    {
        return response(view('pwa.service-worker', [
            'icon' => asset('images/gaiacp/icon-192.png'),
            'home' => url('/admin'),
        ])->render())->header('Content-Type', 'application/javascript; charset=utf-8')->header('Cache-Control', 'no-cache');
    }
}
