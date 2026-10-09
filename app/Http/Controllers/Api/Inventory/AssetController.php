<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryAsset;
use App\Services\Media\CloudFrontUrlSigner;
use App\Services\Media\S3MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function __construct(
        private readonly S3MediaStore $store,
        private readonly CloudFrontUrlSigner $signer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $assets = InventoryAsset::query()
            ->with('category')
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(30);

        $assets->getCollection()->transform(fn (InventoryAsset $asset) => $this->toArray($asset));

        return response()->json($assets);
    }

    public function show(InventoryAsset $asset): JsonResponse
    {
        return response()->json(['data' => $this->toArray($asset->load('category'))]);
    }

    /**
     * Presigned URLs for the asset photo + receipt (image or PDF), uploaded
     * directly to S3 just like media uploads.
     */
    public function presign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:asset_image,receipt'],
            'filename' => ['required', 'string'],
            'content_type' => ['required', 'string'],
        ]);

        $extension = Str::lower(pathinfo($data['filename'], PATHINFO_EXTENSION) ?: 'bin');
        $key = sprintf(
            '%s/%s/%s.%s',
            config('services.aws.inventory_prefix'),
            $data['kind'],
            (string) Str::uuid(),
            $extension,
        );

        return response()->json([
            'data' => [
                's3_key' => $key,
                'upload_url' => $this->store->presignedPutUrl($key, $data['content_type']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $asset = InventoryAsset::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->toArray($asset->load('category'))], 201);
    }

    public function update(Request $request, InventoryAsset $asset): JsonResponse
    {
        $asset->update($this->validated($request, partial: true));

        return response()->json(['data' => $this->toArray($asset->fresh('category'))]);
    }

    public function destroy(InventoryAsset $asset): JsonResponse
    {
        $asset->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'description' => ['nullable', 'string'],
            'asset_image_key' => ['nullable', 'string'],
            'receipt_file_key' => ['nullable', 'string'],
            'receipt_mime' => ['nullable', 'in:image,pdf'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,retired,disposed,needs_repair'],
        ]);
    }

    /** @return array<string, mixed> */
    private function toArray(InventoryAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'category' => $asset->category?->name,
            'category_id' => $asset->category_id,
            'description' => $asset->description,
            'asset_image_url' => $asset->asset_image_key ? $this->signer->signedUrl($asset->asset_image_key) : null,
            'receipt_url' => $asset->receipt_file_key ? $this->signer->signedUrl($asset->receipt_file_key) : null,
            'receipt_mime' => $asset->receipt_mime,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'warranty_expiry' => $asset->warranty_expiry?->toDateString(),
            'warranty_active' => $asset->warrantyActive(),
            'status' => $asset->status,
            'created_at' => $asset->created_at,
        ];
    }
}
