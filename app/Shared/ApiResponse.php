<?php

namespace App\Shared;

use Illuminate\Http\JsonResponse;
use Str;

class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $meta['request_id'] = (string) Str::uuid();

        return response()->json(['data' => $data, 'meta' => $meta], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);
    }

    public static function created(mixed $data = null): JsonResponse
    {
        return self::success($data, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
