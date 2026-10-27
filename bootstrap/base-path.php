<?php

/*
 * Run by public/index.php before anything else. When the site's document root is GaiaCP's own folder, its .htaccess
 * sends every request into public/, so PHP sees SCRIPT_NAME /public/index.php (or /billing/public/index.php in a
 * subfolder) while the address has no "/public" in it. The app's base path is then the folder above public/, or
 * routes would not match and links would get "/public". A document root on public/ (SCRIPT_NAME /index.php) and an
 * address that does contain "/public/" are left as they are.
 */

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$folder = str_replace('\\', '/', dirname($script));

if (str_ends_with($folder, '/public') && ! str_starts_with($_SERVER['REQUEST_URI'] ?? '', $folder.'/')) {
    $_SERVER['SCRIPT_NAME'] = substr($folder, 0, -strlen('/public')).'/index.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
}

unset($script, $folder);
