<?php

namespace Plugins\PiraeusBank;

use RuntimeException;

/** A card payment with Piraeus Bank cannot start (the bank refused the ticket, or could not be reached); the message is safe to show the client. */
class PiraeusBankException extends RuntimeException {}
