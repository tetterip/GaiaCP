<?php

namespace Plugins\Eurobank;

use RuntimeException;

/** A card payment with Eurobank cannot start; the message is safe to show the client. */
class EurobankException extends RuntimeException {}
