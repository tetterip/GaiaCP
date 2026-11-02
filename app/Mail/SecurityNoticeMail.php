<?php

namespace App\Mail;

/**
 * A notice about a login's own security (a passkey added) in the look of the Forgot password? email. Unlike that
 * email it holds no link that logs in, so the email log keeps its text.
 */
class SecurityNoticeMail extends PasswordResetMail {}
