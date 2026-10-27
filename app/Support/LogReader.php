<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Reads Laravel's log files (storage/logs/*.log) for Settings → Error log: splits them into entries
 * ("[2026-10-09 12:00:00] production.ERROR: message" plus the stack trace under it), newest first.
 * Only the end of a large file is read, so the page stays fast.
 */
class LogReader
{
    /** Bytes read from the end of a file. */
    public const TAIL_BYTES = 2 * 1024 * 1024;

    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= storage_path('logs');
    }

    /** @return array<string, string> file name → "name (size)", newest first */
    public function files(): array
    {
        return collect(glob($this->directory.'/*.log') ?: [])
            ->sortByDesc(fn (string $path) => filemtime($path))
            ->mapWithKeys(fn (string $path) => [basename($path) => basename($path).' ('.self::size((int) filesize($path)).')'])
            ->all();
    }

    /** Full path of a log file by name, or null when there is no such file (or the name tries to leave the folder). */
    public function path(?string $file): ?string
    {
        if (blank($file) || basename($file) !== $file || ! str_ends_with($file, '.log')) {
            return null;
        }

        $path = $this->directory.'/'.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * @return Collection<int, array{key: string, at: string, environment: string, level: string, message: string, details: string}>
     */
    public function entries(string $file): Collection
    {
        $path = $this->path($file);

        if ($path === null) {
            return collect();
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        if ($offset > 0) {
            $text = (string) substr($text, (int) strpos($text, "\n") + 1); // drop the partial first line
        }

        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*\] [\w-]+\.[A-Z]+: )/m', $text, flags: PREG_SPLIT_NO_EMPTY);

        return collect($parts)
            ->map(function (string $part, int $i) use ($offset) {
                if (! preg_match('/^\[([^\]]+)\] ([\w-]+)\.([A-Z]+): (.*)$/s', rtrim($part), $m)) {
                    return null; // text before the first entry (cut off by the tail)
                }

                [$message, $details] = array_pad(explode("\n", $m[4], 2), 2, '');

                return [
                    'key' => $offset.'-'.$i,
                    'at' => $m[1],
                    'environment' => $m[2],
                    'level' => strtolower($m[3]),
                    'message' => $message,
                    'details' => $details,
                ];
            })
            ->filter()
            ->reverse()
            ->values();
    }

    public function clear(string $file): bool
    {
        $path = $this->path($file);

        return $path !== null && file_put_contents($path, '') !== false;
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
