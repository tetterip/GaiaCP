<?php

namespace App\Reminders;

use App\Models\Service;
use Carbon\CarbonInterface;

/**
 * Decides which reminder, if any, a service is due on a given day.
 *
 * Kinds are "before:N" (N days or fewer until the due date) and "overdue:N"
 * (N days or more past it). Only the most urgent applicable kind is returned,
 * so a day the scheduler missed is caught up with one email, not several.
 */
class ReminderPlanner
{
    /**
     * @param  list<int>  $beforeDays
     * @param  list<int>  $overdueDays
     */
    public function __construct(
        private array $beforeDays,
        private array $overdueDays,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('gaiacp.reminders.before_days', []),
            config('gaiacp.reminders.overdue_days', []),
        );
    }

    /**
     * @param  list<string>  $alreadySent  kinds already sent for the current due date
     */
    public function kindFor(Service $service, CarbonInterface $today, array $alreadySent = []): ?string
    {
        return $this->kindForDueDate($service->next_due_date, $today, $alreadySent);
    }

    /**
     * @param  list<string>  $alreadySent  kinds already sent for this due date
     */
    public function kindForDueDate(CarbonInterface $dueDate, CarbonInterface $today, array $alreadySent = []): ?string
    {
        $daysUntilDue = (int) $today->copy()->startOfDay()->diffInDays($dueDate, false);

        if ($daysUntilDue >= 0) {
            $applicable = array_filter($this->beforeDays, fn (int $n) => $daysUntilDue <= $n);
            $kind = $applicable ? 'before:'.min($applicable) : null;
        } else {
            $applicable = array_filter($this->overdueDays, fn (int $n) => -$daysUntilDue >= $n);
            $kind = $applicable ? 'overdue:'.max($applicable) : null;
        }

        return $kind !== null && ! in_array($kind, $alreadySent, true) ? $kind : null;
    }

    /** Whether an "overdue:N" kind is the first overdue notice (the smallest N), else a later one. */
    public function isFirstOverdue(string $kind): bool
    {
        return $this->overdueDays !== [] && $kind === 'overdue:'.min($this->overdueDays);
    }

    /** How far ahead the job has to look for services. */
    public function horizonDays(): int
    {
        return $this->beforeDays ? max($this->beforeDays) : 0;
    }
}
