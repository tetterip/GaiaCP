<?php

namespace App\Http\Controllers\Api;

use App\Api\OpenApi;
use App\Models\ApiKey;
use App\Support\Version;
use Illuminate\Http\JsonResponse;

class KeyController extends ApiController
{
    /** GET /: the key's name and what it may do. */
    public function show(): JsonResponse
    {
        $key = ApiKey::current();

        return response()->json([
            'name' => $key->name,
            'permissions' => $key->permissions ?? [],
            'version' => Version::current(),
        ]);
    }

    /** GET /openapi.json */
    public function openApi(): JsonResponse
    {
        return response()->json(OpenApi::spec(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
