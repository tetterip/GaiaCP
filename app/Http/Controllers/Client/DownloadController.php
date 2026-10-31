<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file of Support → Downloads (App\Filament\Client\Pages\Downloads), for whoever it is visible to: visitors only
 * those for everyone; one they may not have is a 404, as is an unpublished one. Each download is counted.
 */
class DownloadController extends Controller
{
    public function __invoke(int $download): StreamedResponse
    {
        $file = Download::query()->findOrFail($download);

        abort_unless($file->isVisibleTo(filament()->auth()->user()?->client), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        $file->increment('download_count');

        return Storage::disk('local')->download($file->path, $file->file_name);
    }
}
