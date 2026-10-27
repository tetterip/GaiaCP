<?php

namespace App\Actions;

use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Provisioning\AccountUsage;
use App\Provisioning\ProvisioningException;
use App\Support\MailTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * The daily usage check (Settings → Automation → Usage warning): reads every active hosting account's disk space and
 * bandwidth from its panel, and one at or above that share of its package's limit gets the Usage Warning email and
 * rings the admins' bell (service.usage_high), once a month for each of the two (usage_warnings). Safe to re-run.
 */
class CheckUsage
{
    public const KINDS = ['disk', 'bandwidth'];

    public function __construct(private ServiceLifecycle $lifecycle, private ClientMailer $mailer) {}

    /** The share that warns; null when the check is off. */
    public static function threshold(): ?int
    {
        $percent = config('gaiacp.automation.usage_warning_percent');

        return $percent === null || $percent === '' || (int) $percent <= 0 ? null : (int) $percent;
    }

    /**
     * @param  callable(Service, array<string, int>): void|null  $report  told of each account with something new to warn about
     * @return array{checked: int, warned: int, failed: int}
     */
    public function handle(CarbonImmutable $today, bool $dryRun = false, ?callable $report = null): array
    {
        $result = ['checked' => 0, 'warned' => 0, 'failed' => 0];
        $threshold = self::threshold();

        if ($threshold === null) {
            return $result;
        }

        $period = $today->format('Y-m');
        $services = Service::query()->where('type', ServiceType::Hosting)->where('status', ServiceStatus::Active)
            ->whereNotNull('server_id')->whereHas('server', fn ($q) => $q->where('active', true))
            ->with(['client', 'server'])->orderBy('id')->get();

        foreach ($services as $service) {
            try {
                $usage = $this->lifecycle->usage($service, fresh: true);
            } catch (ProvisioningException) {
                $result['failed']++; // a server that is down is the monitoring's job

                continue;
            }

            $result['checked']++;

            $high = array_filter([
                'disk' => AccountUsage::percent($usage->diskUsed, $usage->diskLimit),
                'bandwidth' => AccountUsage::percent($usage->bandwidthUsed, $usage->bandwidthLimit),
            ], fn (?int $percent) => $percent !== null && $percent >= $threshold);

            $warned = DB::table('usage_warnings')->where('service_id', $service->id)->where('period', $period)->pluck('kind')->all();
            $new = array_diff_key($high, array_flip($warned));

            if ($new === []) {
                continue;
            }

            $result['warned']++;

            if ($report) {
                $report($service, $new);
            }

            if ($dryRun) {
                continue;
            }

            foreach ($new as $kind => $percent) {
                DB::table('usage_warnings')->insertOrIgnore(['service_id' => $service->id, 'kind' => $kind, 'period' => $period, 'percent' => min($percent, 65535), 'created_at' => now()]);
            }

            $this->mailer->service(MailTemplate::UsageWarning, $service, fn () => [
                'package' => $service->package,
                'usage' => self::table($usage, array_keys($high)),
            ]);

            ActivityLog::record('service.usage_high', sprintf('%s at %s', $service->domain ?: $service->label,
                collect($new)->map(fn (int $percent, string $kind) => ($kind === 'disk' ? 'disk space' : 'bandwidth')." {$percent}%")->join(', ')),
                $service, properties: ['usage' => $new]);
        }

        return $result;
    }

    /** @param  list<string>  $kinds */
    private static function table(AccountUsage $usage, array $kinds): mixed
    {
        $size = fn (?int $bytes) => $bytes === null ? '' : Number::fileSize($bytes, maxPrecision: 1);

        return MailTemplates::table([__('Usage'), __('Used'), __('Limit'), __('Share')], collect($kinds)->map(fn (string $kind) => $kind === 'disk'
            ? [__('Disk space'), $size($usage->diskUsed), $size($usage->diskLimit), AccountUsage::percent($usage->diskUsed, $usage->diskLimit).'%']
            : [__('Bandwidth this month'), $size($usage->bandwidthUsed), $size($usage->bandwidthLimit), AccountUsage::percent($usage->bandwidthUsed, $usage->bandwidthLimit).'%']));
    }
}
