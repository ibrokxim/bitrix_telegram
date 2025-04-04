<?php

namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DealService extends Bitrix24BaseService
{
    protected $stageMap = [
        'new' => 'C1:NEW',
        'processed' => 'C1:PREPARATION',
        'confirmed' => 'C1:PREPAYMENT_INVOICE',
        'shipped' => 'C1:EXECUTING',
        'delivered' => 'C1:FINAL_INVOICE',
        'completed' => 'C1:WON',
        'canceled' => 'C1:LOSE'
    ];

    /**
     * Определяет начальную стадию сделки на основе истории заказов пользователя
     *
     * @param int $userId ID пользователя
     * @return string ID стадии в Битрикс24
     */
    public function determineInitialStage(int $userId): string
    {
        // Получаем количество заказов пользователя
        $orderCount = \App\Models\Order::where('user_id', $userId)
            ->where('status', '!=', 'canceled')
            ->count();

        Log::info('Определение начальной стадии сделки', [
            'user_id' => $userId,
            'order_count' => $orderCount
        ]);

        // Если это первый заказ - отправляем в "Новые"
        if ($orderCount === 0) {
            return 'C1:NEW';
        }

        // Если уже есть заказы - отправляем в "Повторные"
        // Замените 'C1:REPEAT' на реальный ID стадии "Повторные" из вашего Битрикс24
        return 'C1:REPEAT';
    }

    /**
     * Создает новую сделку в Битрикс24
     *
     * @param array $dealData Данные сделки
     * @return array Результат создания сделки
     */
    public function createDeal(array $dealData)
    {
        try {
            // Определяем начальную стадию на основе истории заказов
            $initialStage = $this->determineInitialStage($dealData['user_id'] ?? 0);
            
            // Извлекаем товары и контакт из данных сделки
            $products = $dealData['PRODUCTS'] ?? [];
            $contactId = $dealData['CONTACT_ID'] ?? null;
            unset($dealData['PRODUCTS'], $dealData['CONTACT_ID'], $dealData['user_id']);

            // Добавляем стадию к полям сделки
            $dealData['STAGE_ID'] = $initialStage;

            // Создаем сделку
            $response = Http::post($this->webhookUrl, [
                'method' => 'crm.deal.add',
                'params' => [
                    'fields' => $dealData
                ]
            ]);

            if (!$response->successful()) {
                throw new Exception('Failed to create deal: ' . $response->body());
            }

            $result = $response->json();
            $dealId = $result['result'];

            // Добавляем товары к сделке, если они указаны
            if (!empty($products)) {
                $formattedProducts = array_map(function ($product) {
                    return [
                        'PRODUCT_ID' => $product['id'],
                        'PRODUCT_NAME' => $product['name'],
                        'PRICE' => (float)$product['price'],
                        'QUANTITY' => (float)$product['quantity'],
                        'CURRENCY_ID' => 'UZS',
                    ];
                }, $products);

                $productsResponse = Http::post($this->webhookUrl, [
                    'method' => 'crm.deal.productrows.set',
                    'params' => [
                        'id' => $dealId,
                        'rows' => $formattedProducts
                    ]
                ]);

                if (!$productsResponse->successful()) {
                    Log::error('Ошибка при добавлении товаров к сделке:', [
                        'deal_id' => $dealId,
                        'error' => $productsResponse->body(),
                        'products' => $formattedProducts
                    ]);
                } else {
                    Log::info('Товары успешно добавлены к сделке:', [
                        'deal_id' => $dealId,
                        'products_count' => count($formattedProducts)
                    ]);
                }
            }

            // Добавляем контакт к сделке, если указан
            if ($contactId) {
                $contactResponse = Http::post($this->webhookUrl, [
                    'method' => 'crm.deal.contact.add',
                    'params' => [
                        'id' => $dealId,
                        'fields' => [
                            'CONTACT_ID' => $contactId,
                            'IS_PRIMARY' => 'Y'
                        ]
                    ]
                ]);

                if (!$contactResponse->successful()) {
                    Log::error('Ошибка при добавлении контакта к сделке:', [
                        'deal_id' => $dealId,
                        'contact_id' => $contactId,
                        'error' => $contactResponse->body()
                    ]);
                } else {
                    Log::info('Контакт успешно добавлен к сделке:', [
                        'deal_id' => $dealId,
                        'contact_id' => $contactId
                    ]);
                }
            }

            Log::info('Сделка успешно создана в Битрикс24', [
                'deal_id' => $dealId,
                'stage' => $initialStage,
                'fields' => $dealData
            ]);

            return [
                'status' => 'success',
                'deal_id' => $dealId,
                'stage' => $initialStage
            ];

        } catch (Exception $e) {
            Log::error('Ошибка при создании сделки в Bitrix24: ' . $e->getMessage(), [
                'deal_data' => $dealData,
                'products' => $products ?? [],
                'contact_id' => $contactId ?? null
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
