<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ClientRequest;
use App\Http\Resources\Api\ClientResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Support\ClientFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ClientController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Client::query(), $request, ['email', 'status', 'country']);

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(fn ($q) => $q->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)
                ->orWhere('company', 'like', $search)->orWhere('email', 'like', $search));
        }

        return ClientResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function store(ClientRequest $request): JsonResponse
    {
        $client = Client::query()->create(self::values($request->validated()));
        ActivityLog::record('client.created', "Client {$client->name} created through the API", client: $client);

        return (new ClientResource($client->refresh()))->response()->setStatusCode(201);
    }

    public function update(ClientRequest $request, Client $client): ClientResource
    {
        $data = self::values($request->validated(), $client);

        if (isset($data['currency']) && strtoupper($data['currency']) !== $client->currency && $client->hasBilling()) {
            throw ValidationException::withMessages(['currency' => 'The currency stays as it is once the client has services or payment notices.']);
        }

        $client->fill($data);
        $changed = array_keys($client->getDirty());
        $client->save();

        if ($changed !== []) {
            ActivityLog::record('client.updated', "Client {$client->name} changed through the API (".implode(', ', $changed).')', client: $client);
        }

        return new ClientResource($client->refresh());
    }

    /** Only a client with no services, payments or credit (the panel hides Delete then too). */
    public function destroy(Client $client): JsonResponse
    {
        if ($client->services()->exists() || $client->payments()->exists() || $client->creditTransactions()->exists()) {
            return $this->conflict('A client with services, payments or credit is not deleted; set its status to closed instead.');
        }

        ActivityLog::record('client.deleted', "Client {$client->name} deleted through the API");
        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function values(array $data, ?Client $client = null): array
    {
        // Only the fields of Settings → Client fields; values of deleted fields stay
        if (array_key_exists('custom_fields', $data)) {
            $clean = array_filter(ClientFields::clean(ClientFields::all(), $data['custom_fields'] ?? []), fn ($value) => $value !== null);
            $data['custom_fields'] = array_merge($client?->custom_fields ?? [], $clean) ?: null;
        }

        return $data;
    }
}
