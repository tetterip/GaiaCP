<?php

namespace App\Filament\Client\Actions;

use App\Actions\CertificateLifecycle;
use App\Enums\CertificateStatus;
use App\Enums\ServiceType;
use App\Filament\Certificates\CertificateDownload;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * What a client does with their SSL certificate: download it once issued, and while it waits for validation pick
 * another method or ask for another check. Provider calls go through CertificateLifecycle, logged with their login.
 */
class CertificateSelfService
{
    /** The approver addresses certificate authorities accept, at the domain. */
    private const APPROVERS = ['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster'];

    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::download(), self::changeValidation(), self::checkAgain()];
    }

    private static function download(): Action
    {
        return Action::make('downloadCertificate')
            ->label('Download certificate')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Service $record) => $record->type === ServiceType::Ssl && CertificateDownload::available($record))
            ->action(fn (Service $record) => CertificateDownload::response($record));
    }

    private static function changeValidation(): Action
    {
        return Action::make('changeValidation')
            ->label('Change validation method')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->visible(fn (Service $record) => self::waiting($record))
            ->modalDescription(__('How you prove to the certificate authority that the domain is yours.'))
            ->schema([
                Select::make('method')
                    ->label('Validation')
                    ->options(collect(CertificateLifecycle::METHODS)->mapWithKeys(fn (string $method) => [$method => CertificateLifecycle::methodLabel($method)])->all())
                    ->default(fn (Service $record) => $record->certificateDetails?->dcv_method)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                Select::make('approver_email')
                    ->label('Approval email to')
                    ->options(fn (Service $record) => collect(self::APPROVERS)->mapWithKeys(fn (string $name) => ["{$name}@{$record->domain}" => "{$name}@{$record->domain}"])->all())
                    ->default(fn (Service $record) => "admin@{$record->domain}")
                    ->selectablePlaceholder(false)
                    ->required(fn (Get $get) => $get('method') === 'email')
                    ->visible(fn (Get $get) => $get('method') === 'email'),
            ])
            ->action(function (Service $record, array $data) {
                self::run(fn () => app(CertificateLifecycle::class)->changeValidation($record, $data['method'], $data['approver_email'] ?? null), __('Validation method changed'));
            });
    }

    private static function checkAgain(): Action
    {
        return Action::make('checkValidation')
            ->label(fn (Service $record) => $record->certificateDetails?->dcv_method === 'email' ? 'Send the approval email again' : 'Check again')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Service $record) => self::waiting($record))
            ->action(function (Service $record) {
                self::run(function () use ($record) {
                    $certificates = app(CertificateLifecycle::class);
                    $certificates->recheck($record);
                    $certificates->refresh($record);
                }, __('Asked the certificate authority to check again'));
            });
    }

    private static function waiting(Service $record): bool
    {
        return $record->type === ServiceType::Ssl
            && $record->certificateDetails?->status === CertificateStatus::Pending
            && filled($record->certificateDetails->remote_id);
    }

    private static function run(callable $call, string $success): void
    {
        try {
            $call();
        } catch (ProvisioningException $e) {
            report($e);
            Notification::make()->danger()->title(__('That did not work'))->body(__('Please try again later, or contact us.'))->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
