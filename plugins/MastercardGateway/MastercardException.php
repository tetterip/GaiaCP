<?php

namespace Plugins\MastercardGateway;

use RuntimeException;

/** The gateway could not be reached, or refused a request; the message is for the client or the admin. */
class MastercardException extends RuntimeException {}
