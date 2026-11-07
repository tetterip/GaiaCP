<?php

namespace App\Actions;

use RuntimeException;

/**
 * The cycle (or the upgrade line) a payment was meant for was paid by another request a moment earlier: found under the
 * service's row lock, so two payments of the same notice landing at once never move the due date twice.
 */
class AlreadyPaid extends RuntimeException {}
