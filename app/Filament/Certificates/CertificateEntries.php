<?php

namespace App\Filament\Certificates;

use App\Actions\CertificateLifecycle;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;

/** An SSL certificate's state and what the client does to validate it: on the admin's service form and the client's service page. */
class CertificateEntries
{
    /** @return list<Component> */
    public static function make(bool $client = false): array
    {
        return [
            TextEntry::make('certificate_status')
                ->label('Certificate')
                ->state(fn (?Service $record) => self::certificate($record)?->status ?? CertificateStatus::NotOrdered)
                ->badge()
                ->helperText(fn (?Service $record) => $client ? null : self::certificate($record)?->error),
            TextEntry::make('certificate_name')
                ->label('Issued for')
                ->state(fn (?Service $record) => self::certificate($record)?->commonName())
                ->placeholder('—'),
            TextEntry::make('certificate_expires_at')
                ->label('Valid until')
                ->state(fn (?Service $record) => self::certificate($record)?->expires_at?->format(AppServiceProvider::dateFormat()))
                ->placeholder('—'),
            TextEntry::make('certificate_ends_at')
                ->label('Order ends')
                ->state(fn (?Service $record) => self::certificate($record)?->ends_at?->format(AppServiceProvider::dateFormat()))
                ->helperText(__('A certificate lasts at most 200 days: a new one is issued in the order before it ends.'))
                ->placeholder('—')
                ->visible(! $client),
            TextEntry::make('certificate_order')
                ->label('Provider order')
                ->state(fn (?Service $record) => ($certificate = self::certificate($record)) && filled($certificate->remote_id) ? "{$certificate->provider} #{$certificate->remote_id}" : null)
                ->placeholder('—')
                ->visible(! $client),
            TextEntry::make('certificate_method')
                ->label('Validation')
                ->state(fn (?Service $record) => CertificateLifecycle::methodLabel(self::certificate($record)?->dcv_method))
                ->helperText(fn (?Service $record) => CertificateLifecycle::methodHelp(self::certificate($record)?->dcv_method))
                ->visible(fn (?Service $record) => self::waiting($record))
                ->columnSpanFull(),
            KeyValueEntry::make('certificate_validation')
                ->hiddenLabel()
                ->keyLabel(__('What'))
                ->valueLabel(__('Enter exactly'))
                ->state(fn (?Service $record) => collect(CertificateLifecycle::instructions(self::certificate($record)))->mapWithKeys(fn (array $line) => [$line['label'] => $line['value']])->all())
                ->visible(fn (?Service $record) => self::waiting($record) && CertificateLifecycle::instructions(self::certificate($record)) !== [])
                ->columnSpanFull(),
        ];
    }

    private static function certificate(?Service $record): ?Certificate
    {
        return $record?->certificateDetails;
    }

    private static function waiting(?Service $record): bool
    {
        return self::certificate($record)?->status === CertificateStatus::Pending;
    }
}
