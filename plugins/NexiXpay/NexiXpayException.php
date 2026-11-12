<?php

namespace Plugins\NexiXpay;

use RuntimeException;

/** Nexi could not be reached, or refused a request; the message is for the client or the admin. */
class NexiXpayException extends RuntimeException {}
