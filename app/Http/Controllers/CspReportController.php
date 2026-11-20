<?php

namespace App\Http\Controllers;

use App\Support\ContentSecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What a browser says the Content-Security-Policy blocked (or would block, on "report"), as one warning in the log
 * (System → Logs), so a plugin or script it stops can be found. Anyone can post here: short, throttled, nothing kept else.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $report = json_decode(Str::limit((string) $request->getContent(), 8000, ''), true);
        $report = is_array($report) ? ($report['csp-report'] ?? $report[0]['body'] ?? $report) : null;

        if (is_array($report)) {
            $field = fn (string ...$names) => Str::limit((string) collect($names)->map(fn ($name) => $report[$name] ?? null)->first(fn ($value) => is_scalar($value) && $value !== ''), 300);

            Log::warning(ContentSecurityPolicy::mode() === ContentSecurityPolicy::REPORT ? 'Content-Security-Policy would block' : 'Content-Security-Policy blocked', [
                'directive' => $field('effective-directive', 'effectiveDirective', 'violated-directive'),
                'blocked' => $field('blocked-uri', 'blockedURL'),
                'page' => $field('document-uri', 'documentURL'),
                'source' => $field('source-file', 'sourceFile'),
                'line' => $field('line-number', 'lineNumber'),
                'sample' => $field('script-sample', 'sample'),
            ]);
        }

        return response()->noContent();
    }
}
