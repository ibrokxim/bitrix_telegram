<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\BitrixWebhookController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Bitrix24EventController;

Route::get('/catalogs', [ProductController::class, 'getCatalogs']);
Route::get('/product/{id}', [ProductController::class, 'getProductById']);
Route::get('/products/category/{sectionId}', [ProductController::class, 'getProducts']);
Route::post('/cart/add/{productId}', [ProductController::class, 'addToCart']);
Route::get('/cart', [ProductController::class, 'viewCart']);
Route::post('/checkout', [ProductController::class, 'checkout']);

Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/process-user-request', [RegistrationController::class, 'processUserRequest']);
Route::post('/place-order', [OrderController::class, 'placeOrder']);
Route::post('/check-auth', [OrderController::class, 'checkAuth']);
Route::post('/webhook/telegram', [TelegramController::class, 'handleWebhook']);
Route::post('/webhook/bitrix/deal', [BitrixWebhookController::class, 'handleDealUpdate']);
Route::post('/verify-phone', [RegistrationController::class, 'verifyExistingUser']);
Route::get('/users/list', [RegistrationController::class, 'listUsers']);
Route::post('/bitrix24/event', [Bitrix24EventController::class, 'handleEvent']);

// Тестовый маршрут для проверки доступности
Route::get('/bitrix24/test', function() {
    return response()->json([
        'status' => 'success',
        'message' => 'API endpoint is accessible',
        'timestamp' => now()->toIso8601String()
    ]);
});
