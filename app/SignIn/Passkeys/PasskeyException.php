<?php

namespace App\SignIn\Passkeys;

use RuntimeException;

/** A passkey the browser sent that can't be accepted (the reason is for the log, not for the user). */
class PasskeyException extends RuntimeException {}
