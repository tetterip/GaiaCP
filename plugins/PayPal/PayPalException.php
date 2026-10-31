<?php

namespace Plugins\PayPal;

use RuntimeException;

/** PayPal refused a call or could not be reached; the message is safe to show an admin. */
class PayPalException extends RuntimeException {}
