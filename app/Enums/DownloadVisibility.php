<?php

namespace App\Enums;

/** Who sees a download (Support → Downloads) in the client area. */
enum DownloadVisibility: string
{
    /** Visitors too, without logging in. */
    case Everyone = 'everyone';

    /** Any client who is logged in. */
    case Clients = 'clients';

    /** Clients with an active service of one of the download's products. */
    case Products = 'products';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => __('Everyone, visitors too'),
            self::Clients => __('Clients who are logged in'),
            self::Products => __('Clients with one of these products'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
