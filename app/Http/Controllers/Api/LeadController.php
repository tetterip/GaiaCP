<?php

namespace App\Http\Controllers\Api;

use App\Actions\Leads;
use App\Http\Requests\Api\LeadRequest;
use App\Http\Resources\Api\LeadResource;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Leads (Clients → Leads) through Leads: a form on the website adds one, and the bell tells Sales. Converting one and
 * marking it lost happen in the panel, where its notes are.
 */
class LeadController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Lead::query(), $request, ['status', 'source', 'email', 'assigned_to', 'client_id']);

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(fn ($q) => $q->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)
                ->orWhere('company', 'like', $search)->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search));
        }

        return LeadResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Lead $lead): LeadResource
    {
        return new LeadResource($lead);
    }

    public function store(LeadRequest $request, Leads $leads): JsonResponse
    {
        $data = $request->validated();
        if (filled($data['email'] ?? null)) {
            $data['email'] = strtolower(trim($data['email']));
        }

        return (new LeadResource($leads->create($data)->refresh()))->response()->setStatusCode(201);
    }

    public function update(LeadRequest $request, Lead $lead): JsonResponse|LeadResource
    {
        if (! $lead->status->isOpen()) {
            return $this->conflict('A won or lost lead is not changed; reopen a lost one in the panel.');
        }

        $lead->fill($request->validated());
        $changed = array_keys($lead->getDirty());
        $lead->save();

        if ($changed !== []) {
            ActivityLog::record('lead.updated', "Lead {$lead->displayName()} changed through the API (".implode(', ', $changed).')', properties: ['lead_id' => $lead->id]);
        }

        return new LeadResource($lead->refresh());
    }

    /** Not one that became a client (the panel hides Delete then too). */
    public function destroy(Lead $lead): JsonResponse
    {
        if ($lead->client_id !== null) {
            return $this->conflict('A lead that became a client is not deleted.');
        }

        ActivityLog::record('lead.deleted', "Lead {$lead->displayName()} deleted through the API");
        $lead->delete();

        return response()->json(null, 204);
    }
}
