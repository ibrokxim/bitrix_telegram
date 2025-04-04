<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\Bitrix24EventController;
// use App\Http\Controllers\BitrixWebhookController;
use App\Services\Bitrix24\ProductService;
use App\Services\Bitrix24\DealService;
use Illuminate\Http\Request;
use App\Http\Controllers\DealController;

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
//Route::post('/webhook/bitrix/deal', [BitrixWebhookController::class, 'handleDealUpdate']);
Route::post('/verify-phone', [RegistrationController::class, 'verifyExistingUser']);
Route::get('/users/list', [RegistrationController::class, 'listUsers']);
Route::get('/bitrix24/event', [Bitrix24EventController::class, 'handleEvent']);

// Тестовый маршрут для проверки доступности
Route::get('/bitrix24/test', function() {
    return response()->json([
        'status' => 'success',
        'message' => 'API endpoint is accessible',
        'timestamp' => now()->toIso8601String()
    ]);
});

// Тестовый маршрут для добавления товаров к сделке
Route::post('/bitrix24/test-products', function (Request $request) {
    $dealId = $request->input('deal_id');
    $products = $request->input('products', []);

    if (!$dealId || empty($products)) {
        return response()->json([
            'success' => false,
            'message' => 'Необходимо указать deal_id и products'
        ], 400);
    }

    $productService = app(ProductService::class);
    $result = $productService->addProductsToDeal($dealId, $products);

    return response()->json([
        'success' => $result,
        'message' => $result ? 'Товары успешно добавлены' : 'Ошибка при добавлении товаров',
        'deal_id' => $dealId,
        'products' => $products
    ]);
});

// Тестовый маршрут для создания сделки с определением стадии
Route::post('/bitrix24/test-deal', function (Request $request) {
    $userId = $request->input('user_id');
    $dealData = $request->input('deal_data', []);

    if (!$userId) {
        return response()->json([
            'success' => false,
            'message' => 'Необходимо указать user_id'
        ], 400);
    }

    $dealService = app(DealService::class);
    
    // Добавляем user_id к данным сделки
    $dealData['user_id'] = $userId;
    
    $dealId = $dealService->createDeal($dealData);

    return response()->json([
        'success' => $dealId !== null,
        'message' => $dealId !== null ? 'Сделка успешно создана' : 'Ошибка при создании сделки',
        'deal_id' => $dealId,
        'stage' => $dealService->determineInitialStage($userId)
    ]);
});

// Маршруты для работы со сделками
Route::get('/deals/available-products', [DealController::class, 'getAvailableProducts']);
Route::post('/deals', [DealController::class, 'createDeal']);
