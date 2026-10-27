<?php

namespace App\Filament\Actions;

use App\Actions\CertificateLifecycle;
use App\Actions\SetUpService;
use App\Enums\CertificateStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Certificates\CertificateDownload;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The "SSL" menu on an SSL certificate service: every action goes through CertificateLifecycle, so provider calls,
 * status changes and logging stay in one place.
 */
class CertificateActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            self::order(),
            self::check(),
            self::download(),
            self::changeValidation(),
            self::recheck(),
            self::reissue(),
            self::renew(),
            self::cancel(),
        ])
            ->label('SSL')
            ->icon(Heroicon::OutlinedLockClosed)
            ->button()
            ->color('gray');
    }

    private static function order(): Action
    {
        return Action::make('orderCertificate')
            ->label('Order the certificate')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (Service $record) => self::isSsl($record) && ! $record->certificateDetails?->ordered() && in_array($record->status, [ServiceStatus::Pending, ServiceStatus::Active], true))
            ->modalDescription(__('Orders it from the provider, which charges your account there. The client then validates the domain.'))
            ->schema([
                self::methodSelect(),
                Textarea::make('csr')
                    ->label('CSR (optional)')
                    ->rows(4)
                    ->helperText(__('Leave empty and GaiaCP makes the private key and CSR. A CSR of the client\'s own means GaiaCP never has their key.')),
            ])
            ->action(self::run(function (Service $record, CertificateLifecycle $certificates, array $data) {
                $certificates->order($record, $data['csr'] ?? null, $data['method']);

                if ($record->order) {
                    app(SetUpService::class)->completeIfDone($record->order);
                }
            }, __('Ordered')));
    }

    private static function check(): Action
    {
        return Action::make('checkCertificate')
            ->label('Check status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Service $record) => self::isSsl($record) && filled($record->certificateDetails?->remote_id))
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates) => $certificates->refresh($record), __('Status read')));
    }

    private static function download(): Action
    {
        return Action::make('downloadCertificate')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Service $record) => self::isSsl($record) && CertificateDownload::available($record))
            ->action(fn (Service $record) => CertificateDownload::response($record));
    }

    private static function changeValidation(): Action
    {
        return Action::make('changeValidation')
            ->label('Change validation')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn (Service $record) => self::isSsl($record) && self::waiting($record))
            ->schema([
                self::methodSelect()->default(fn (Service $record) => $record->certificateDetails?->dcv_method),
                TextInput::make('approver_email')
                    ->label('Approver email')
                    ->email()
                    ->placeholder(fn (Service $record) => 'admin@'.$record->domain)
                    ->helperText(__('One of admin@, administrator@, hostmaster@, postmaster@ or webmaster@ the domain.'))
                    ->visible(fn (Get $get) => $get('method') === 'email'),
            ])
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates, array $data) => $certificates->changeValidation($record, $data['method'], $data['approver_email'] ?? null), __('Validation changed')));
    }

    private static function recheck(): Action
    {
        return Action::make('recheckValidation')
            ->label(fn (Service $record) => $record->certificateDetails?->dcv_method === 'email' ? 'Resend the approval email' : 'Check the validation again')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Service $record) => self::isSsl($record) && self::waiting($record))
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates) => $certificates->recheck($record), __('Asked the certificate authority to check again')));
    }

    private static function reissue(): Action
    {
        return Action::make('reissueCertificate')
            ->label('Reissue')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (Service $record) => self::isSsl($record) && $record->certificateDetails?->status === CertificateStatus::Issued)
            ->modalDescription(__('A new certificate in the same order, with a new private key: free at the provider. The client validates the domain again.'))
            ->schema([
                Textarea::make('csr')
                    ->label('CSR (optional)')
                    ->rows(4)
                    ->helperText(__('Leave empty and GaiaCP makes a new private key and CSR.')),
            ])
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates, array $data) => $certificates->reissue($record, $data['csr'] ?? null), __('Reissue requested')));
    }

    private static function renew(): Action
    {
        return Action::make('renewCertificate')
            ->label('Renew now')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->visible(fn (Service $record) => self::isSsl($record) && $record->certificateDetails?->status === CertificateStatus::Issued)
            ->requiresConfirmation()
            ->modalDescription(fn (Service $record) => __('A new order for the next period, which the provider charges now. The hourly check does this on its own once the service is paid beyond :date.', [
                'date' => $record->certificateDetails?->ends_at?->format(AppServiceProvider::dateFormat()) ?? __('the order\'s end'),
            ]))
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates) => $certificates->renew($record), __('Renewal ordered')));
    }

    private static function cancel(): Action
    {
        return Action::make('cancelCertificate')
            ->label('Cancel at the provider')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Service $record) => self::isSsl($record) && (bool) $record->certificateDetails?->ordered())
            ->requiresConfirmation()
            ->modalDescription(__('Cancels the order (and revokes an issued certificate). The provider refunds it only within its refund days. The service itself stays as it is.'))
            ->schema([
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->default(__('Cancelled by the client')),
            ])
            ->action(self::run(fn (Service $record, CertificateLifecycle $certificates, array $data) => $certificates->cancel($record, $data['reason']), __('Cancelled')));
    }

    private static function methodSelect(): Select
    {
        return Select::make('method')
            ->label('Validation')
            ->options(collect(CertificateLifecycle::METHODS)->mapWithKeys(fn (string $method) => [$method => CertificateLifecycle::methodLabel($method)])->all())
            ->default(fn (Service $record) => $record->certificateDetails?->dcv_method ?? 'dns')
            ->selectablePlaceholder(false)
            ->required()
            ->live();
    }

    private static function isSsl(Service $record): bool
    {
        return $record->type === ServiceType::Ssl;
    }

    private static function waiting(Service $record): bool
    {
        return $record->certificateDetails?->status === CertificateStatus::Pending && filled($record->certificateDetails->remote_id);
    }

    /**
     * @param  Closure(Service, CertificateLifecycle, array<string, mixed>): void  $call
     */
    private static function run(Closure $call, string $success): Closure
    {
        return function (Service $record, array $data, $livewire) use ($call, $success) {
            try {
                $call($record, app(CertificateLifecycle::class), $data);
            } catch (ProvisioningException $e) {
                Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

                return;
            }

            $status = $record->fresh('certificateDetails')->certificateDetails?->status;
            Notification::make()->title($success)->body($status?->getLabel())->success()->send();

            if (method_exists($livewire, 'refreshFormData')) {
                $livewire->refreshFormData(['status']);
            }
        };
    }
}
