<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\CancellationType;
use App\Enums\ChangeRequestStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\CancellationRequestMail;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\CancellationRequest;
use App\Models\Service;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Support\Locales;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * A client asking from the client area to cancel a service, like WHMCS's cancellation requests: immediately or at the
 * end of the billing period, with a reason, and optionally the domain of the same name not to renew. Nothing changes
 * until an admin approves: Immediately terminates (on the panel too), At the end sets Auto-terminate (no more payment
 * notices; terminated on the due date). The domain gets Auto renew off. A service has at most one pending request.
 */
class CancellationRequests
{
    public function __construct(private ServiceLifecycle $lifecycle, private ClientMailer $mailer) {}

    /** Whether the client may ask: an active or suspended service, not a domain (those turn Auto renew off), not ending already. */
    public static function canRequest(Service $service): bool
    {
        return $service->type !== ServiceType::Domain
            && in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && ! $service->auto_terminate
            && ! $service->cancellationRequests()->pending()->exists();
    }

    /** The client's domain of the same name that still renews, offered with the request. */
    public static function domainOf(Service $service): ?Service
    {
        $domain = TransferService::domainOf($service);

        return $domain && $domain->auto_renew ? $domain : null;
    }

    public function request(Service $service, CancellationType $type, ?string $reason, bool $withDomain = false): CancellationRequest
    {
        if (! self::canRequest($service)) {
            throw new InvalidArgumentException('This service cannot be cancelled from the client area.');
        }

        $request = $service->cancellationRequests()->create([
            'client_id' => $service->client_id,
            'type' => $type,
            'reason' => filled($reason) ? trim($reason) : null,
            'domain_service_id' => $withDomain ? self::domainOf($service)?->id : null,
        ]);

        ActivityLog::record('service.cancellation_requested', "Cancellation requested ({$type->value})".($request->domain_service_id ? ', with its domain' : '').($request->reason ? ": {$request->reason}" : ''), $service, properties: ['request_id' => $request->id]);
        $this->notifyAdmins($request);

        return $request;
    }

    /** The client changed their mind before an admin decided. */
    public function withdraw(CancellationRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $request->delete();
        ActivityLog::record('service.cancellation_withdrawn', 'Cancellation request withdrawn by the client', $request->service);
    }

    /** @throws ProvisioningException when terminating on the panel fails; the request then stays pending */
    public function approve(CancellationRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $service = $request->service;

        if ($request->type === CancellationType::Immediate) {
            $this->lifecycle->terminate($service);
        } elseif (! $service->auto_terminate) {
            $service->update(['auto_terminate' => true, 'auto_terminate_reason' => $request->reason ?: __('Cancellation requested by the client')]);
        }

        if (($domain = $request->domainService) && ! $domain->auto_terminate) {
            $domain->update(['auto_terminate' => true, 'auto_terminate_reason' => __('Cancellation requested by the client')]);
            ActivityLog::record('domain.auto_renew_off', 'Auto renew turned off: its hosting was cancelled', $domain);
        }

        $this->decide($request, ChangeRequestStatus::Approved);
        ActivityLog::record('service.cancellation_approved', $request->type === CancellationType::Immediate
            ? 'Cancellation request approved: terminated'
            : "Cancellation request approved: ends on {$service->next_due_date->toDateString()}", $service);

        $this->mailer->service(MailTemplate::CancellationApproved, $service->fresh(), fn () => $this->values($request));
    }

    public function reject(CancellationRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $this->decide($request, ChangeRequestStatus::Rejected);
        ActivityLog::record('service.cancellation_rejected', 'Cancellation request declined', $request->service);

        $this->mailer->service(MailTemplate::CancellationRejected, $request->service, fn () => $this->values($request));
    }

    /** @return array<string, string> the template placeholders of a request, in the language in use */
    private function values(CancellationRequest $request): array
    {
        return [
            'cancellation_type' => $request->type->getLabel(),
            'cancellation_date' => ($request->type === CancellationType::Immediate ? now() : $request->service->next_due_date)
                ->format(AppServiceProvider::dateFormat()),
        ];
    }

    private function decide(CancellationRequest $request, ChangeRequestStatus $status): void
    {
        $request->update(['status' => $status, 'decided_by' => auth()->guard('web')->id(), 'decided_at' => now()]);
    }

    /** To the admins who open services and receive system emails, while Settings → Automation allows it. */
    private function notifyAdmins(CancellationRequest $request): void
    {
        if (! config('gaiacp.automation.cancellation_request_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Services));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new CancellationRequestMail($request));
            } catch (Throwable $e) {
                Log::error('Cancellation request email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Cancellation request email to {$admin->email} failed: {$e->getMessage()}", $request->service);
            }
        }
    }
}
