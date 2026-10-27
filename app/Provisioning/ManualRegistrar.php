<?php

namespace App\Provisioning;

/**
 * A RegistrarModule that books nothing at any registrar (plugins/EmailRegistrar): its DomainRegistrar tells the
 * admins what to do instead. DomainLifecycle never reads from it (no sync, no EPP code to show), so a domain there
 * keeps the expiry date and nameservers entered on it.
 */
interface ManualRegistrar {}
