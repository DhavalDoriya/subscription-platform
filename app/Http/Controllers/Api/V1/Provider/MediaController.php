<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Requests\Provider\UpdateMediaRequest;
use App\Http\Requests\Provider\UploadMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Media library of the signed-in provider (rows are looked up here, so another provider's id is a 404). */
class MediaController extends Controller
{
    public function __construct(private MediaService $media, private TenantContext $tenancy) {}

    public function index(ListRequest $request): JsonResponse
    {
        $media = Media::query()
            ->when($request->search(), fn ($query, $search) => $query->whereRaw('lower(original_name) like ?', ['%'.mb_strtolower($search).'%']))
            ->latest()
            ->paginate($request->perPage());

        return ApiResponse::paginated($media, MediaResource::class);
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        return ApiResponse::created(new MediaResource($this->media->store($this->tenancy->current(), $request->file('file'))));
    }

    public function update(UpdateMediaRequest $request, string $media): JsonResponse
    {
        $model = Media::findOrFail($media);
        $model->update($request->validated());

        return ApiResponse::success(new MediaResource($model));
    }

    public function destroy(string $media): JsonResponse
    {
        $this->media->delete(Media::findOrFail($media));

        return ApiResponse::noContent();
    }
}
