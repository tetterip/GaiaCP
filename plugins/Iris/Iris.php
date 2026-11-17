<?php

namespace Plugins\Iris;

use Illuminate\Support\Facades\Storage;

/** The IRIS details clients pay to (Settings → IRIS). */
class Iris
{
    /** The disk and folder of the uploaded QR code: private, it is shown inline (dataUri()) and never linked. */
    public const DISK = 'local';

    public const DIRECTORY = 'iris';

    public const QR_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Whether clients have something to pay to: a number or a QR code. */
    public static function configured(): bool
    {
        return filled(self::number()) || self::qrPath() !== null;
    }

    public static function payee(): ?string
    {
        return self::setting('payee') ?? self::setting('company_name');
    }

    public static function number(): ?string
    {
        return self::setting('number');
    }

    public static function note(): ?string
    {
        return self::setting('note');
    }

    /** The stored QR code's path on its disk, or null when there is none (or the file went missing). */
    public static function qrPath(): ?string
    {
        $file = self::setting('qr');

        return $file !== null && Storage::disk(self::DISK)->exists($file) ? $file : null;
    }

    /** The QR code as a data: URI for an <img>, or null without one. */
    public static function qrDataUri(?string $file = null): ?string
    {
        $file ??= self::qrPath();

        if ($file === null || ! Storage::disk(self::DISK)->exists($file)) {
            return null;
        }

        $mime = Storage::disk(self::DISK)->mimeType($file);

        if (! in_array($mime, self::QR_TYPES, true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk(self::DISK)->get($file));
    }

    /** The plain text "how to pay" lines for notices and emails; null when not set up. */
    public static function instructions(): ?string
    {
        if (! self::configured()) {
            return null;
        }

        $lines = [__('IRIS: pay from your bank\'s app with IRIS.')];

        if (filled(self::number())) {
            $lines[] = filled(self::payee())
                ? __('IRIS number: :number (:payee)', ['number' => self::number(), 'payee' => self::payee()])
                : __('IRIS number: :number', ['number' => self::number()]);
        }

        if (self::qrPath() !== null) {
            $lines[] = __('The QR code is under Pay with IRIS on the payment notice in our client area.');
        }

        $lines[] = __('Please write the payment notice number as the reason.');

        if (filled(self::note())) {
            $lines[] = self::note();
        }

        return implode("\n", $lines);
    }

    private static function setting(string $key): ?string
    {
        $value = $key === 'company_name' ? config('gaiacp.company.name') : config('gaiacp.iris.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
