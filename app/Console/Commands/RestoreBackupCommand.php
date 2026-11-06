<?php

namespace App\Console\Commands;

use App\Backups\RestoreBackup;
use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gaiacp:restore {file : the backup zip (gaiacp-backup-….zip)} {--password= : the zip password, if it has one} {--force : do not ask first} {--database= : a database connection other than the default}')]
#[Description('Put a backup back: replaces the database and writes its files (System → Help → Keeping GaiaCP healthy)')]
class RestoreBackupCommand extends Command
{
    public function handle(): int
    {
        if (Demo::enabled()) {
            $this->error('The demo is put back by gaiacp:demo-reset, not from backups.');

            return self::FAILURE;
        }

        try {
            $restore = new RestoreBackup($this->argument('file'), $this->option('password'));

            if ($restore->encrypted() && ! $this->option('password') && $this->input->isInteractive()) {
                $restore = new RestoreBackup($this->argument('file'), (string) $this->secret('Zip password'));
            }

            $info = $restore->inspect();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Backup made %s (%s database), %d files, %s.', $info['created'] ?? '(unknown date)', $info['driver'] ?? 'unknown', $info['files'], $info['key'] ? 'with the APP_KEY' : 'without the APP_KEY'));

        if (! $this->option('force') && ! $this->confirm('This REPLACES the whole database of this GaiaCP with the backup\'s and writes its files over the ones here. Continue?')) {
            return self::FAILURE;
        }

        $this->call('down', ['--retry' => 60]);

        try {
            $result = $restore($this->option('database') ?: null);
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());
            $this->line('The site stays in maintenance mode: fix the cause and run the restore again, or `php artisan up`.');

            return self::FAILURE;
        }

        $this->call('up');

        $this->info("Restored {$result['tables']} tables and {$result['files']} files.");
        $this->line(match ($result['key']) {
            'set' => 'APP_KEY in .env set from the backup: stored passwords can be read.',
            'same' => 'The APP_KEY is the same as the backup\'s.',
            'not-writable' => 'The backup has an APP_KEY but .env could not be changed: copy app-key.txt from the zip into APP_KEY in .env yourself.',
            default => 'The backup has no APP_KEY: unless .env has the one of the install that made it, enter the server, registrar and other passwords again.',
        });
        $this->line('Sign in again: sessions start fresh after a restore.');

        return self::SUCCESS;
    }
}
