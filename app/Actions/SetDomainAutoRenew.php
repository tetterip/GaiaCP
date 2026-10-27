<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\Service;

/** A client turns their domain's auto renew on or off in the client area (off = auto-terminate at the end of the cycle). */
class SetDomainAutoRenew
{
    public function handle(Service $service, bool $on): void
    {
        if ($service->auto_renew === $on) {
            return;
        }

        $service->auto_renew = $on;
        $service->auto_terminate_reason = $on ? null : 'Auto renew turned off by the client';
        $service->save();

        ActivityLog::record('domain.auto_renew_changed', "Auto renew of {$service->domain} turned ".($on ? 'on' : 'off').' in the client area', $service);
    }
}
