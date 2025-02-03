<?php

// app/Http/Controllers/ImageProxyController.php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;

class ImageProxyController extends Controller
{
    protected $client;
    protected $authToken;
    protected $baseUrl;

    public function __construct()
    {
        $this->client = new Client();
        $this->authToken = "q4a0r1ia6r10uagz";
        $this->baseUrl = "https://kadyrovmedical.bitrix24.kz";
    }

    public function getImage($productId, $fileId)
    {
        $cacheKey = "product_image_{$productId}_{$fileId}";
        $storagePath = "products/{$productId}/{$fileId}.jpg";

        // Проверяем, есть ли файл в локальном хранилище
        if (Storage::exists($storagePath)) {
            return response()->file(Storage::path($storagePath));
        }

        try {
            // Формируем URL для загрузки изображения
            $imageUrl = "{$this->baseUrl}/bitrix/components/bitrix/crm.product.file/download.php";

            // Получаем изображение с авторизацией
            $response = $this->client->get($imageUrl, [
                'query' => [
                    'productId' => $productId,
                    'fieldName' => 'PROPERTY_45',
                    'fileId' => $fileId,
                    'auth' => $this->authToken,
                    'dynamic' => 'Y'
                ]
            ]);

            // Создаем директорию, если её нет
            Storage::makeDirectory("products/{$productId}");

            // Сохраняем файл
            Storage::put($storagePath, $response->getBody()->getContents());

            return response()->file(Storage::path($storagePath));
        } catch (\Exception $e) {
            \Log::error('Error proxying image:', [
                'productId' => $productId,
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Image not found'], 404);
        }
    }
}
