<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Success response — wraps data in the standard envelope.
     *
     * { "success": true, "data": {...}, "message": "..." }
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * Created response — 201 with resource data.
     */
    public static function created(mixed $data, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * Error response — standardized failure envelope.
     *
     * { "success": false, "data": null, "message": "...", "errors": {...} }
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = []
    ): JsonResponse {
        $body = [
            'success' => false,
            'data'    => null,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    public static function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return self::error($message, 403);
    }

    public static function notFound(string $message = 'Not found'): JsonResponse
    {
        return self::error($message, 404);
    }

    public static function conflict(string $message = 'Conflict'): JsonResponse
    {
        return self::error($message, 409);
    }

    public static function unprocessable(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }
}
