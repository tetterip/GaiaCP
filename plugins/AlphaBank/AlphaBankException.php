<?php

namespace Plugins\AlphaBank;

use RuntimeException;

/** A card payment with Alpha Bank cannot start; the message is safe to show the client. */
class AlphaBankException extends RuntimeException {}
