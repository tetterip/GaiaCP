<?php

namespace App\Ai;

use RuntimeException;

/** The AI assistant gave no answer (off, over the limit, the service failed); the message is shown to the admin. */
class AiException extends RuntimeException {}
