<?php

namespace Plugins\GoogleLogin;

use RuntimeException;

/** A Google sign-in that can't go on; the message is shown to the visitor on the login page. */
class GoogleLoginException extends RuntimeException {}
