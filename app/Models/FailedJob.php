<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A queued job that failed every try (Laravel's own `failed_jobs`), listed under Settings → Failed jobs. */
class FailedJob extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** The job's class, or what the queue calls it (e.g. a mailable's), from its payload. */
    public function jobName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return (string) ($payload['displayName'] ?? $payload['job'] ?? __('Unknown job'));
    }

    /** The exception's first line, without its stack trace. */
    public function errorLine(): string
    {
        return Str::limit(trim(strtok((string) $this->exception, "\n") ?: ''), 200);
    }
}
