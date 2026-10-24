<?php

namespace App\Exceptions;

use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Maps every exception raised under /api/* to the shared error envelope. */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->getMessage(), $e->status, $e->errors, $e->errorCode),
            $e instanceof ValidationException => ApiResponse::error($e->getMessage(), 422, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error(__('api.errors.unauthenticated'), 401),
            $e instanceof AuthorizationException => ApiResponse::error($e->getMessage() ?: __('api.errors.unauthorized'), 403),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(__('api.errors.not_found'), 404),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(__('api.errors.method_not_allowed'), 405, headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() ?: self::reason($e->getStatusCode()),
                $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),
            default => self::serverError($e),
        };
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $response = ApiResponse::error(__('api.errors.server_error'), 500);

        if (config('app.debug')) {
            $response->setData(array_merge($response->getData(true), ['debug' => [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]]));
        }

        return $response;
    }

    private static function reason(int $status): string
    {
        $key = match ($status) {
            400 => 'bad_request',
            403 => 'forbidden',
            409 => 'conflict',
            419 => 'session_expired',
            429 => 'too_many_requests',
            503 => 'service_unavailable',
            default => 'request_failed',
        };

        return __('api.errors.'.$key);
    }
}
