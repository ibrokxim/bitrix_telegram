<?php

use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;


Route::post('/telegram/webhook', [TelegramController::class, 'handleWebhook']);
// routes/web.php
Route::get('/images/products/{productId}/{fileId}', [ImageProxyController::class, 'getImage'])
    ->name('product.image');
