<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Support\LoginSecurity;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:admin-ips {--clear : Empty the list, so /admin opens from anywhere again}')]
#[Description('Show the addresses that may open /admin (Settings → Login security), or empty the list after a lock-out')]
class AdminIps extends Command
{
    public function handle(): int
    {
        $list = LoginSecurity::adminIps();

        if (! $this->option('clear')) {
            $this->line($list === [] ? '/admin opens from any address.' : "/admin opens only from:\n".implode("\n", $list));

            return self::SUCCESS;
        }

        Settings::save(['login_security.admin_ips' => null]);
        ActivityLog::record('settings.admin_ips_cleared', 'Admin panel address list emptied from the command line');

        $this->info('/admin opens from any address again. Set the list anew in Settings → Login security.');

        return self::SUCCESS;
    }
}
