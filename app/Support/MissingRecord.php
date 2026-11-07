<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A Filament record page (edit / view, admin or client area) whose record is gone, most often after Delete followed by
 * the browser's Back, or an old link from an email or the bell: instead of a bare 404 page, go to that resource's
 * list with a "no longer exists" notice. The server's redirect replaces the dead page in the browser's history.
 */
class MissingRecord
{
    public static function redirect(NotFoundHttpException $e, Request $request): ?RedirectResponse
    {
        if (! $e->getPrevious() instanceof ModelNotFoundException || ! $request->isMethod('GET') || $request->expectsJson()) {
            return null;
        }

        $route = $request->route();
        $name = $route?->getName();

        if (! $name || ! str_starts_with($name, 'filament.') || ! str_contains($name, '.resources.') || str_ends_with($name, '.index')) {
            return null;
        }

        $index = substr($name, 0, strrpos($name, '.')).'.index';

        if (! Route::has($index)) {
            return null;
        }

        try {
            $url = route($index, array_diff_key($route->parameters(), ['record' => true]));
        } catch (Throwable) {
            return null; // a nested resource whose parent is gone too: the plain 404
        }

        Notification::make()
            ->warning()
            ->title(__('This item no longer exists. It may have been deleted.'))
            ->send();

        return redirect()->to($url);
    }
}
