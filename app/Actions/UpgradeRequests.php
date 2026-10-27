<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\ChangeRequestStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Mail\ClientMailer;
use App\Mail\UpgradeRequestMail;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Service;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Support\Locales;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * A client asking from the client area to move a service to another product (upgrade / downgrade). Nothing changes
 * until an admin approves, which runs ChangeProduct: the new package on the server at once and the new price from the
 * next due date, or with "charge the difference now". The client gets Upgrade Request Approved / Declined. A service
 * has at most one pending request.
 */
class UpgradeRequests
{
    public function __construct(private ChangeProduct $change, private ClientMailer $mailer) {}

    /** @return Builder<Product> what the client may ask for: ChangeProduct's choices that clients can order */
    public static function products(Service $service): Builder
    {
        return ChangeProduct::products($service)->clientOrderable();
    }

    /** An active service (not an add-on) with a product it can move to and no request waiting. */
    public static function canRequest(Service $service): bool
    {
        return $service->parent_service_id === null
            && ChangeProduct::changeable($service)
            && $service->status === ServiceStatus::Active
            && ! $service->auto_terminate
            && ! $service->upgradeRequests()->pending()->exists()
            && ! $service->cancellationRequests()->pending()->exists()
            && self::products($service)->get()->contains(fn (Product $product) => ChangeProduct::price($service, $product) !== null);
    }

    public function request(Service $service, Product $product, ?string $notes = null): UpgradeRequest
    {
        if (! self::canRequest($service) || ! self::products($service)->whereKey($product->id)->exists()
            || ($price = ChangeProduct::price($service, $product)) === null) {
            throw new InvalidArgumentException('This service cannot move to that product from the client area.');
        }

        $request = $service->upgradeRequests()->create([
            'client_id' => $service->client_id,
            'product_id' => $product->id,
            'price_cents' => $price,
            'notes' => filled($notes) ? trim($notes) : null,
        ]);

        ActivityLog::record('service.upgrade_requested', sprintf('Change to %s requested (%s)%s', $product->name,
            Money::format($price, $service->currency), $request->notes ? ": {$request->notes}" : ''), $service, properties: ['request_id' => $request->id]);
        $this->notifyAdmins($request);

        return $request;
    }

    /** The client changed their mind before an admin decided. */
    public function withdraw(UpgradeRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $request->delete();
        ActivityLog::record('service.upgrade_withdrawn', 'Change of product request withdrawn by the client', $request->service);
    }

    /**
     * @return array{difference: int, invoice: mixed, credited: int} ChangeProduct's result
     *
     * @throws ProvisioningException when changing the package on the panel fails; the request then stays pending
     * @throws InvalidArgumentException when the service can no longer move to that product
     */
    public function approve(UpgradeRequest $request, int $priceCents, bool $chargeNow = false): array
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            throw new InvalidArgumentException('This request was already decided.');
        }

        $result = $this->change->handle($request->service, $request->product, $priceCents, $chargeNow);

        $this->decide($request, ChangeRequestStatus::Approved);
        ActivityLog::record('service.upgrade_approved', "Change to {$request->product->name} approved", $request->service);

        $service = $request->service->fresh();
        $this->mailer->service(MailTemplate::UpgradeApproved, $service, fn () => [
            'new_product' => $request->product->name,
            'new_price' => Money::format($service->price_cents, $service->currency),
            'effective_date' => $service->next_due_date->format(AppServiceProvider::dateFormat()),
        ]);

        return $result;
    }

    public function reject(UpgradeRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $this->decide($request, ChangeRequestStatus::Rejected);
        ActivityLog::record('service.upgrade_rejected', "Change to {$request->product->name} declined", $request->service);

        $this->mailer->service(MailTemplate::UpgradeRejected, $request->service, fn () => ['new_product' => $request->product->name]);
    }

    private function decide(UpgradeRequest $request, ChangeRequestStatus $status): void
    {
        $request->update(['status' => $status, 'decided_by' => auth()->guard('web')->id(), 'decided_at' => now()]);
    }

    /** To the admins who open services and receive system emails, while Settings → Automation allows it. */
    private function notifyAdmins(UpgradeRequest $request): void
    {
        if (! config('gaiacp.automation.upgrade_request_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Services));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new UpgradeRequestMail($request));
            } catch (Throwable $e) {
                Log::error('Upgrade request email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Upgrade request email to {$admin->email} failed: {$e->getMessage()}", $request->service);
            }
        }
    }
}
