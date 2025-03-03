<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\BitrixWebhookController;

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
