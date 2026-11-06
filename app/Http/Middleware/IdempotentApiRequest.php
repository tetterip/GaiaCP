<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A REST API POST sent with an `Idempotency-Key` header runs once per key: sent again with the same key and the same
 * method, path and body (a retry after a timeout or a lost answer), it gets the first answer back, marked
 * `Idempotent-Replayed: true`, and nothing is done twice. The same key with another call is refused (422), and a retry
 * that arrives while the first call is still running waits for it (409, Retry-After). Keys are per API key, kept for
 * KEEP_HOURS hours. Answers of 500 and over (the API answers 502 only when nothing changed) and 401 / 403 / 429 are
 * not kept, so those calls can be tried again with the same key.
 */
class IdempotentApiRequest
{
    public const HEADER = 'Idempotency-Key';

    public const KEEP_HOURS = 24;

    private const TABLE = 'api_idempotency_keys';

    /** Answers that changed nothing and may change on a retry (a permission or IP added, the rate limit over). */
    private const NOT_KEPT = [401, 403, 429];

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER));
        $apiKey = ApiKey::current();

        if (! $request->isMethod('POST') || $key === '' || $apiKey === null) {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header may be at most 255 characters.'], 422);
        }

        $fingerprint = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $old = DB::table(self::TABLE)->where('api_key_id', $apiKey->id)->where('idempotency_key', $key)->first();

        // An old row (not pruned yet) no longer counts
        if ($old && now()->subHours(self::KEEP_HOURS)->gt($old->created_at)) {
            DB::table(self::TABLE)->where('id', $old->id)->delete();
            $old = null;
        }

        if ($old === null) {
            try {
                $id = DB::table(self::TABLE)->insertGetId([
                    'api_key_id' => $apiKey->id,
                    'idempotency_key' => $key,
                    'fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same key arrived twice at once: the other call holds it
                return $this->running();
            }

            return $this->first($request, $next, $id);
        }

        if (! hash_equals($old->fingerprint, $fingerprint)) {
            return response()->json(['message' => 'This Idempotency-Key was already used for another call: use a new key for each new call.'], 422);
        }

        if ($old->status === null) {
            return $this->running();
        }

        return response((string) $old->response, (int) $old->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }

    /** Runs the call once and keeps its answer for the key. */
    private function first(Request $request, Closure $next, int $id): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table(self::TABLE)->where('id', $id)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500 || in_array($response->getStatusCode(), self::NOT_KEPT, true)) {
            DB::table(self::TABLE)->where('id', $id)->delete();
        } else {
            DB::table(self::TABLE)->where('id', $id)->update(['status' => $response->getStatusCode(), 'response' => (string) $response->getContent()]);
        }

        return $response;
    }

    private function running(): JsonResponse
    {
        return response()->json(['message' => 'A call with this Idempotency-Key is still running: try again in a moment.'], 409, ['Retry-After' => '1']);
    }

    /** Rows past KEEP_HOURS (gaiacp:prune-logs). */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->where('created_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
    }
}
