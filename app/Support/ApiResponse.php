<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Single place that decides the JSON envelope of every Dukavo API response.
 *
 * Controllers therefore never hand-roll `response()->json([...])`, and clients
 * can rely on one shape:
 *
 *   { "success": true,  "message": "...", "data": { ... } }
 *   { "success": false, "message": "...", "errors": { ... } }
 *
 * Resources stay responsible for *what* a payload contains; this class is only
 * responsible for the wrapper around it.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        string $message = '',
        JsonResource|ResourceCollection|array|null $data = null,
        int $status = Response::OK,
        array $meta = [],
    ): JsonResponse {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            $extra = ['success' => true];

            if ($message !== '') {
                $extra['message'] = $message;
            }

            if ($meta !== []) {
                $extra['meta'] = $meta;
            }

            return $data->additional($extra)->response()->setStatusCode($status);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ] + ($meta !== [] ? ['meta' => $meta] : []), $status);
    }

    public static function created(string $message, JsonResource|ResourceCollection|array|null $data = null): JsonResponse
    {
        return self::success($message, $data, Response::CREATED);
    }

    public static function noContent(string $message = ''): JsonResponse
    {
        return self::success($message, null, Response::NO_CONTENT);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    public static function error(string $message, int $status = Response::BAD_REQUEST, array $errors = [], array $extra = []): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message] + $extra;

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status);
    }
}
