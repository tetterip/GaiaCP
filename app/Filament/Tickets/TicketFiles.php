<?php

namespace App\Filament\Tickets;

use App\Actions\Tickets;
use App\Models\TicketAttachment;
use App\Support\Demo;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Number;
use Symfony\Component\Mime\MimeTypes;

/** The Attach files field of a ticket message, on both panels: the private "local" disk, Settings → Support's size and types. */
class TicketFiles
{
    public static function make(): FileUpload
    {
        $types = Tickets::allowedExtensions();
        $maxKb = max(1, (int) config('gaiacp.support.attachments_max_kb', 5120));

        return FileUpload::make('attachments')
            ->label('Attach files')
            ->hidden(Demo::enabled()) // nobody's files are shown to every visitor of the demo
            ->multiple()
            ->maxFiles(10)
            ->disk('local')
            ->directory('tickets')
            ->visibility('private')
            ->storeFileNamesIn('attachment_names')
            ->maxSize($maxKb)
            ->acceptedFileTypes(collect($types)->flatMap(fn (string $ext) => MimeTypes::getDefault()->getMimeTypes($ext))->unique()->values()->all())
            ->helperText(__('Up to :size each: :types', ['size' => Number::fileSize($maxKb * 1024), 'types' => implode(', ', $types)]));
    }

    /**
     * Only files the field has just stored: Filament keeps any string the browser sends in the field's state, so a
     * path that is not a new upload's name (tickets/ + 40 random characters, as hashName() gives) or that already
     * belongs to a message is dropped. Otherwise "tickets/../backups/..." or another client's file could be attached.
     *
     * @param  array<string, mixed>  $data  a form's state with the field above
     * @return array<string, string> stored path => the file's own name, for App\Actions\Tickets
     */
    public static function from(array $data): array
    {
        $names = (array) ($data['attachment_names'] ?? []);
        $paths = collect((array) ($data['attachments'] ?? []))
            ->filter(fn ($path) => is_string($path) && preg_match('~\Atickets/[A-Za-z0-9]{40}(\.[A-Za-z0-9]{1,10})?\z~', $path));
        $taken = TicketAttachment::query()->whereIn('path', $paths->all())->pluck('path')->all();

        return $paths
            ->reject(fn (string $path) => in_array($path, $taken, true))
            ->mapWithKeys(fn (string $path) => [$path => (string) ($names[$path] ?? basename($path))])
            ->all();
    }
}
