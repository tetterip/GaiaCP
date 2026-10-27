<?php

namespace App\Http\Middleware;

use App\Support\Health\SchedulerMonitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rings the admins' bell once when cron has stopped (SchedulerMonitor::alertIfStopped()). A stopped cron can't say so
 * itself, so a signed-in admin's page load checks it: one cache read while cron runs.
 */
class AlertWhenCronStops
{
    public function __construct(private SchedulerMonitor $monitor) {}

    public function handle(Request $request, Closure $next): Response
    {
        rescue(fn () => $this->monitor->alertIfStopped()); // never let monitoring stop the panel

        return $next($request);
    }
}
