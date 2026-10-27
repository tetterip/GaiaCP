<?php

namespace App\Plugins\Catalogue;

use RuntimeException;

/** The plugin catalogue could not be read or trusted, or a plugin zip from it failed its checks; shown to the admin. */
class CatalogueException extends RuntimeException {}
