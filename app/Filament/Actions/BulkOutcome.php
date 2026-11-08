<?php

namespace App\Filament\Actions;

use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What a mass action did to the records picked in a list: how many it changed, which it left out and why, and which
 * failed with what error. Every record is tried, so one failure never stops the others; the admin sees one summary.
 */
class BulkOutcome
{
    private int $done = 0;

    /** @var array<string, int> reason => records skipped for it */
    private array $skipped = [];

    /** @var array<string, string> record label => error */
    private array $failed = [];

    /**
     * Runs $apply on each record that $skip gives no reason for (a translated text), catching what it throws.
     *
     * @param  iterable<mixed>  $records
     * @param  callable(mixed): ?string  $skip
     * @param  callable(mixed): void  $apply
     * @param  callable(mixed): string  $label
     */
    public static function run(iterable $records, callable $skip, callable $apply, callable $label): self
    {
        $outcome = new self;

        foreach ($records as $record) {
            if (($reason = $skip($record)) !== null) {
                $outcome->skipped[$reason] = ($outcome->skipped[$reason] ?? 0) + 1;

                continue;
            }

            try {
                $apply($record);
                $outcome->done++;
            } catch (Throwable $e) {
                report($e);
                $outcome->failed[$label($record)] = $e->getMessage();
            }
        }

        return $outcome;
    }

    /**
     * Before it runs: "Applies to 8 of the 10 picked. 2 left out: …", from the same $skip.
     *
     * @param  Collection<int, mixed>  $records
     * @param  callable(mixed): ?string  $skip
     */
    public static function preview(Collection $records, callable $skip): string
    {
        $reasons = $records->map($skip)->filter()->countBy();
        $applies = $records->count() - $reasons->sum();

        $text = __('Applies to :count of the :total picked.', ['count' => $applies, 'total' => $records->count()]);

        return $reasons->isEmpty() ? $text : $text.' '.self::reasons($reasons->all());
    }

    public function done(): int
    {
        return $this->done;
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return array<string, string> */
    public function failed(): array
    {
        return $this->failed;
    }

    /** One notification: green when all went, orange when some were left out, red (and kept) when any failed. */
    public function notify(string $doneTitle): void
    {
        $lines = array_filter([
            $this->skipped !== [] ? self::reasons($this->skipped) : null,
            ...collect($this->failed)->map(fn (string $error, string $label) => "{$label}: {$error}")->values()->all(),
        ]);

        $notification = Notification::make()
            ->title(trans_choice($doneTitle, $this->done))
            ->body($lines === [] ? null : implode("\n", $lines));

        match (true) {
            $this->failed !== [] => $notification->danger()->persistent(),
            $this->skipped !== [] => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }

    /** @param  array<string, int>  $reasons */
    private static function reasons(array $reasons): string
    {
        return collect($reasons)->map(fn (int $count, string $reason) => __('Left out (:count): :reason', ['count' => $count, 'reason' => $reason]))->join(' ');
    }
}
