<?php

namespace App\Support\Api;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only place that builds API response bodies, so every endpoint speaks one envelope:
 *
 *   item:   { "data": {...} }
 *   list:   { "data": [...], "meta": { current_page, per_page, total, last_page } }
 *   error:  { "message": "...", "errors"?: {...}, "code"?: "..." }   (see ApiExceptionRenderer)
 */
final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['data' => self::resolve($data)], $status);
    }

    public static function created(mixed $data = null): JsonResponse
    {
        return self::success($data, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param  Closure|class-string<JsonResource>|null  $transform  maps each item to an array
     */
    public static function paginated(LengthAwarePaginator $paginator, Closure|string|null $transform = null): JsonResponse
    {
        $items = $paginator->getCollection();

        $data = match (true) {
            $transform instanceof Closure => $items->map($transform)->values()->all(),
            is_string($transform) => $transform::collection($items)->resolve(),
            default => $items->values()->all(),
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function error(string $message, int $status, ?array $errors = null, ?string $code = null, array $headers = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
        ], fn ($value) => $value !== null), $status, $headers);
    }

    private static function resolve(mixed $data): mixed
    {
        return $data instanceof JsonResource ? $data->resolve() : $data;
    }
}
