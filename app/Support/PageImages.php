<?php

namespace App\Support;

use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The images put in a page's text with the editor (Support → Pages, App\Models\CustomPage): kept on the public disk
 * under pages/ with a random name, and served by PageImageController (route `page-image`), so they show without
 * `storage:link` and from a subfolder install. An image's id in the text is its path; any other id shows nothing.
 */
final class PageImages implements FileAttachmentProvider
{
    public const DISK = 'public';

    public const FOLDER = 'pages';

    /** What the editor takes: pictures only (no SVG, which can carry scripts). */
    public const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** At most 5 MB each (in KB, as Filament counts). */
    public const MAX_KB = 5120;

    /** The file name of an image's id (pages/<name>), or null when the id is not one of these images. */
    public static function name(mixed $id): ?string
    {
        if (! is_string($id) || ! preg_match('#^'.self::FOLDER.'/([A-Za-z0-9]{20,64}\.(png|jpe?g|gif|webp))$#', $id, $match)) {
            return null;
        }

        return $match[1];
    }

    public static function url(mixed $id): ?string
    {
        $name = self::name($id);

        return $name === null || ! Storage::disk(self::DISK)->exists(self::FOLDER.'/'.$name) ? null : route('page-image', ['file' => $name]);
    }

    /** The full path of an image by its file name, or null when there is none. */
    public static function path(string $name): ?string
    {
        $id = self::FOLDER.'/'.$name;

        return self::name($id) !== null && Storage::disk(self::DISK)->exists($id) ? Storage::disk(self::DISK)->path($id) : null;
    }

    public static function store(UploadedFile $file): ?string
    {
        if (! in_array($file->getMimeType(), self::TYPES, true)) {
            return null;
        }

        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return $file->storeAs(self::FOLDER, Str::random(40).'.'.$extension, self::DISK) ?: null;
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        return self::url($file);
    }

    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): mixed
    {
        return self::store($file);
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return 'public';
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /** Images are left in place: the same one can be in several languages' texts, or in a page saved later. */
    public function cleanUpFileAttachments(array $exceptIds): void {}
}
