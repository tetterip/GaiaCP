<?php

namespace App\Mail;

use App\Backups\BackupResult;
use App\Backups\RunBackup;
use App\Providers\AppServiceProvider;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admin (Settings → Backups → Email to), not a client: the backup zip, or why the backup failed. */
class BackupMail extends Mailable
{
    public function __construct(public BackupResult $result) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: match (true) {
            ! $this->result->created() => "[{$name}] ".__('Backup FAILED'),
            ! $this->result->ok() => "[{$name}] ".__('Backup :date (with errors)', ['date' => now()->format(AppServiceProvider::dateFormat())]),
            default => "[{$name}] ".__('Backup :date', ['date' => now()->format(AppServiceProvider::dateFormat())]),
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.backup', with: [
            'result' => $this->result,
            'file' => $this->result->path ? basename($this->result->path) : null,
            'attached' => $this->attaches(),
            'encrypted' => (bool) config('gaiacp.backups.zip_password'),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->attaches() ? [Attachment::fromPath($this->result->path)->withMime('application/zip')] : [];
    }

    private function attaches(): bool
    {
        return $this->result->created() && $this->result->size <= RunBackup::MAX_ATTACHMENT_BYTES;
    }
}
