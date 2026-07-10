<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e): JsonResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiException($e);
        }

        return parent::render($request, $e);
    }

    private function renderApiException(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException
                => ApiResponse::unprocessable($e->errors(), 'Validation failed'),

            $e instanceof AuthenticationException
                => ApiResponse::unauthorized('Authentication required'),

            $e instanceof AccessDeniedHttpException
                => ApiResponse::forbidden('You are not authorized to perform this action'),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException
                => ApiResponse::notFound('The requested resource was not found'),

            $e instanceof DomainException
                => ApiResponse::error($e->getMessage(), 422),

            $e instanceof ConflictException
                => ApiResponse::conflict($e->getMessage()),

            default
                => ApiResponse::error(
                    app()->environment('production') ? 'An unexpected error occurred' : $e->getMessage(),
                    500
                ),
        };
    }
}
