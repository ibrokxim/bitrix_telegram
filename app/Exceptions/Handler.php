<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Support\Facades\Auth;
use App\Services\ErrorHandlerService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            try {
                $currentUser = Auth::user();
                $context = [
                    'url' => request()->fullUrl(),
                    'method' => request()->method(),
                    'inputs' => request()->all(),
                    'user' => $currentUser ? [
                        'id' => $currentUser->id,
                        'name' => $currentUser->name,
                        'email' => $currentUser->email
                    ] : null
                ];

                $errorHandler = app(ErrorHandlerService::class);
                $errorHandler->handleError($e, $context);
            } catch (\Exception $logException) {
                parent::report($logException);
            }
        });
    }
}
