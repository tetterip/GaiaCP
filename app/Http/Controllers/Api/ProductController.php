<?php

namespace App\Http\Controllers\Api;

use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Http\Requests\Api\ProductRequest;
use App\Http\Resources\Api\ProductResource;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The catalogue (Products). A product with services is retired, not deleted, as in the panel. */
class ProductController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Product::query()->with('servers'), $request, ['type', 'addon', 'hidden', 'retired']);

        return ProductResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('servers'));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = new Product;
            $this->save($product, $request->validated());
            ActivityLog::record('product.created', "Product {$product->name} created through the API", properties: ['product_id' => $product->id]);

            return $product;
        });

        return (new ProductResource($product->load('servers')))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        DB::transaction(function () use ($request, $product) {
            $changed = $this->save($product, $request->validated());

            if ($changed !== []) {
                ActivityLog::record('product.updated', "Product {$product->name} changed through the API (".implode(', ', $changed).')', properties: ['product_id' => $product->id]);
            }
        });

        return new ProductResource($product->refresh()->load('servers'));
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->services()->exists()) {
            return $this->conflict('A product with services is not deleted; retire it instead (PATCH with "retired": true).');
        }

        ActivityLog::record('product.deleted', "Product {$product->name} deleted through the API", properties: ['product_id' => $product->id]);
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> the fields that changed
     */
    private function save(Product $product, array $data): array
    {
        if (array_key_exists('prices', $data)) {
            $data['prices'] = collect($data['prices'])->map(fn (array $price) => [
                'enabled' => true,
                'price_cents' => (int) $price['price_cents'],
                'setup_cents' => (int) ($price['setup_cents'] ?? 0),
            ])->all();
        }

        $product->fill(Arr::except($data, ['server_ids']));

        // The default cycle must have a price: the first priced one when none (or an unpriced one) is given
        $priced = array_keys($product->prices ?? []);
        $cycle = $product->billing_cycle instanceof BillingCycle ? $product->billing_cycle->value : $product->billing_cycle;
        if (! in_array($cycle, $priced, true)) {
            if (array_key_exists('billing_cycle', $data)) {
                throw ValidationException::withMessages(['billing_cycle' => 'Give a price for the default cycle.']);
            }
            $product->billing_cycle = collect(BillingCycle::cases())->first(fn (BillingCycle $c) => in_array($c->value, $priced, true));
        }

        $changed = array_keys($product->getDirty());
        $product->save();

        if (array_key_exists('server_ids', $data)) {
            if ($product->type !== ServiceType::Hosting && $data['server_ids'] !== []) {
                throw ValidationException::withMessages(['server_ids' => 'Only hosting products go on servers.']);
            }
            if ($product->servers()->sync($data['server_ids']) !== ['attached' => [], 'detached' => [], 'updated' => []]) {
                $changed[] = 'servers';
            }
        }

        return $changed;
    }
}
