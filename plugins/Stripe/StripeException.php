<?php

namespace Plugins\Stripe;

use RuntimeException;

/** Stripe refused a call or could not be reached; the message is safe to show an admin. */
class StripeException extends RuntimeException {}
