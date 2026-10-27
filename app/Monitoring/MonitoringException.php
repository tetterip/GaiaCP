<?php

namespace App\Monitoring;

use RuntimeException;

/** A monitoring service could not be read; the message is shown to the admin. */
class MonitoringException extends RuntimeException {}
