<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Support\Facades\Log;

class DealController extends Controller
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
    }

    /**
     * Получает список доступных товаров
     */
    public function getAvailableProducts()
    {
        try {
            $result = $this->bitrix24Service->dealService->getAvailableProducts();

            if ($result['status'] === 'error') {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

            return response()->json([
                'success' => true,
                'products' => $result['products']
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка при получении списка товаров: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить список товаров'
            ], 500);
        }
    }

    /**
     * Создает сделку с товарами
     */
    public function createDeal(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string',
                'products' => 'required|array',
                'products.*.id' => 'required|integer',
                'products.*.quantity' => 'required|integer|min:1',
                'products.*.price' => 'nullable|numeric|min:0',
                'products.*.price_exclusive' => 'nullable|numeric|min:0',
                'products.*.tax_rate' => 'nullable|numeric|min:0',
                'products.*.tax_included' => 'nullable|in:Y,N',
                'products.*.discount_sum' => 'nullable|numeric|min:0',
                'products.*.discount_rate' => 'nullable|numeric|between:0,100'
            ]);

            // Получаем список товаров из Битрикс24
            $availableProducts = $this->bitrix24Service->dealService->getAvailableProducts();
            if ($availableProducts['status'] === 'error') {
                throw new Exception($availableProducts['message']);
            }

            // Создаем индекс товаров для быстрого поиска
            $productsIndex = [];
            foreach ($availableProducts['products'] as $product) {
                $productsIndex[$product['ID']] = $product;
            }

            // Форматируем товары для сделки
            $dealProducts = [];
            foreach ($request->products as $productData) {
                if (!isset($productsIndex[$productData['id']])) {
                    throw new Exception("Товар с ID {$productData['id']} не найден");
                }

                $product = $productsIndex[$productData['id']];
                $options = [
                    'quantity' => $productData['quantity']
                ];

                // Добавляем опциональные параметры
                if (isset($productData['price'])) {
                    $options['price'] = $productData['price'];
                }
                if (isset($productData['price_exclusive'])) {
                    $options['price_exclusive'] = $productData['price_exclusive'];
                }
                if (isset($productData['tax_rate'])) {
                    $options['tax_rate'] = $productData['tax_rate'];
                    $options['tax_included'] = $productData['tax_included'] ?? 'N';
                }
                if (isset($productData['discount_sum'])) {
                    $options['discount_sum'] = $productData['discount_sum'];
                }
                if (isset($productData['discount_rate'])) {
                    $options['discount_rate'] = $productData['discount_rate'];
                }

                $dealProducts[] = $this->bitrix24Service->dealService->formatProductRow($product, $options);
            }

            // Создаем сделку с товарами
            $result = $this->bitrix24Service->dealService->createDealWithProducts([
                'TITLE' => $request->title
            ], $dealProducts);

            if ($result['status'] === 'error') {
                throw new Exception($result['message']);
            }

            return response()->json([
                'success' => true,
                'deal_id' => $result['deal_id']
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка при создании сделки: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
} 