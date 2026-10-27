<?php

namespace Plugins\Vies;

use RuntimeException;

/** A VIES check that could not be made (bad input, VIES or the member state's service unavailable). */
class ViesException extends RuntimeException {}
