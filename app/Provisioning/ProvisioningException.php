<?php

namespace App\Provisioning;

use RuntimeException;

/** A panel or registrar API call failed; the message is safe to show and log. */
class ProvisioningException extends RuntimeException {}
