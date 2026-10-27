<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The REST API's controllers (/api/v1, routes/api.php). Each one calls the same Actions as the panel, which log what
 * they do; what the panel saves straight from a form is logged here, all with the key (ApiKey::current()).
 */
abstract class ApiController extends Controller
{
    /** How many records a list returns: ?per_page=, 25 by default, at most gaiacp.api.max_per_page. */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), (int) config('gaiacp.api.max_per_page', 100)));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns  filters the list takes as ?column=value
     */
    protected function filter(Builder $query, Request $request, array $columns): Builder
    {
        foreach ($columns as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->query($column));
            }
        }

        return $query;
    }

    /** A call that can't be done in the record's state (409), with why. */
    protected function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    /** A panel or registrar call that failed (502): nothing changed in GaiaCP. */
    protected function failed(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 502);
    }
}
