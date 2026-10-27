<?php

namespace App\Backups;

use Illuminate\Support\Number;

/** What one backup run did: the zip, where copies went and what failed (keyed backup / ftp / email). */
class BackupResult
{
    public ?string $path = null;

    public int $size = 0;

    /** @var list<string> */
    public array $copies = [];

    /** @var list<string> */
    public array $pruned = [];

    /** @var array<string, string> */
    public array $errors = [];

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function created(): bool
    {
        return ! isset($this->errors['backup']);
    }

    public function sizeForHumans(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }
}
