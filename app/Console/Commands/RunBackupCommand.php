<?php

namespace App\Console\Commands;

use App\Backups\RunBackup;
use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:backup')]
#[Description('Zip a SQL dump of the database and send it to FTP and/or email (Settings → Backups)')]
class RunBackupCommand extends Command
{
    public function handle(RunBackup $backup): int
    {
        if (Demo::enabled()) {
            $this->error('The demo makes no backups: it is put back by gaiacp:demo-reset.');

            return self::FAILURE;
        }

        $result = $backup();

        if ($result->created()) {
            $this->info(sprintf('Created %s (%s)%s.', $result->path, $result->sizeForHumans(), $result->copies ? ', sent to '.implode(' and ', $result->copies) : ''));
        }

        foreach ($result->pruned as $file) {
            $this->line("Deleted old backup {$file}");
        }

        foreach ($result->errors as $copy => $error) {
            $this->error("{$copy}: {$error}");
        }

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
