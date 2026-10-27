<?php

namespace Plugins\TicketMail;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;

/** The support mailbox from Settings → Email to ticket (replaced by a fake one in tests). */
class MailboxConnector
{
    public function configured(): bool
    {
        return filled(config('gaiacp.ticket_mail.host')) && filled(config('gaiacp.ticket_mail.username'));
    }

    public function mailbox(): MailboxInterface
    {
        $encryption = (string) config('gaiacp.ticket_mail.encryption');

        return new Mailbox([
            'host' => (string) config('gaiacp.ticket_mail.host'),
            'port' => (int) (config('gaiacp.ticket_mail.port') ?: ($encryption === 'ssl' ? 993 : 143)),
            'encryption' => in_array($encryption, ['ssl', 'starttls'], true) ? $encryption : null,
            'validate_cert' => (bool) config('gaiacp.ticket_mail.verify_tls'),
            'username' => (string) config('gaiacp.ticket_mail.username'),
            'password' => (string) config('gaiacp.ticket_mail.password'),
            'timeout' => 30,
        ]);
    }

    /** The folder it reads (INBOX unless another is set). */
    public function folder(MailboxInterface $mailbox): FolderInterface
    {
        $folder = trim((string) config('gaiacp.ticket_mail.folder'));

        return $folder === '' || strtoupper($folder) === 'INBOX' ? $mailbox->inbox() : $mailbox->folders()->findOrFail($folder);
    }
}
