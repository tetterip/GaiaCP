<?php

namespace App\Http\Controllers\Api;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Http\Requests\Api\SuspendServiceRequest;
use App\Http\Requests\Api\UpdateServiceRequest;
use App\Http\Resources\Api\ServiceResource;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Services are made by orders; ended by Terminate (needs services.delete), never deleted. */
class ServiceController extends ApiController
{
    public function __construct(private ServiceLifecycle $lifecycle) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Service::query()->with('domainDetails'), $request, ['client_id', 'order_id', 'type', 'status', 'product_id', 'server_id', 'parent_service_id']);

        if ($request->filled('domain')) {
            $query->where('domain', strtolower((string) $request->query('domain')));
        }

        return ServiceResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service->load('domainDetails'));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $data = $request->validated();

        // A domain is called by its name
        if ($service->type === ServiceType::Domain) {
            unset($data['label']);
        }

        if (array_key_exists('auto_terminate', $data)) {
            $data['auto_terminate_reason'] = $data['auto_terminate'] ? 'Set to end at its due date through the API' : null;
        }

        $service->fill($data);
        $changed = array_keys(array_diff_key($service->getDirty(), ['auto_terminate_reason' => true]));
        $service->save();

        if ($changed !== []) {
            ActivityLog::record('service.updated', 'Changed through the API ('.implode(', ', $changed).')', $service);
        }

        return new ServiceResource($service->refresh()->load('domainDetails'));
    }

    public function suspend(SuspendServiceRequest $request, Service $service): JsonResponse|ServiceResource
    {
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return $this->conflict('Only active or suspended services can be suspended.');
        }

        return $this->lifecycle(fn () => $this->lifecycle->suspend($service, $request->validated('reason') ?: 'Suspended through the API'), $service);
    }

    public function unsuspend(Service $service): JsonResponse|ServiceResource
    {
        return $this->lifecycle(fn () => $this->lifecycle->unsuspend($service), $service);
    }

    public function terminate(Service $service): JsonResponse|ServiceResource
    {
        return $this->lifecycle(fn () => $this->lifecycle->terminate($service), $service);
    }

    private function lifecycle(callable $action, Service $service): JsonResponse|ServiceResource
    {
        try {
            $action();
        } catch (ProvisioningException $e) {
            return $this->failed($e->getMessage());
        }

        return new ServiceResource($service->refresh()->load('domainDetails'));
    }
}
