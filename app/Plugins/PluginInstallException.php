<?php

namespace App\Plugins;

use RuntimeException;

/** A plugin zip that was refused, or an install / uninstall that failed; the message is shown to the admin. */
class PluginInstallException extends RuntimeException {}
