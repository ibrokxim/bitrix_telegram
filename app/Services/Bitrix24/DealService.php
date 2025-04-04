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
     * @param array $fields Поля сделки
     * @return int|null ID созданной сделки или null в случае ошибки
     */
    public function createDeal(array $fields): ?int
    {
        try {
            // Определяем начальную стадию на основе истории заказов
            $initialStage = $this->determineInitialStage($fields['user_id'] ?? 0);
            
            // Добавляем стадию к полям сделки
            $fields['STAGE_ID'] = $initialStage;

            $response = Http::post($this->webhookUrl, [
                'method' => 'crm.deal.add',
                'params' => [
                    'fields' => $fields
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('Сделка успешно создана в Битрикс24', [
                    'fields' => $fields,
                    'result' => $result
                ]);
                return $result['result'] ?? null;
            }

            Log::error('Ошибка при создании сделки в Битрикс24', [
                'fields' => $fields,
                'response' => $response->json()
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('Исключение при создании сделки в Битрикс24', [
                'fields' => $fields,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function createDeal(array $dealData)
    {
        try {
            // Извлекаем товары и контакт из данных сделки
            $products = $dealData['PRODUCTS'] ?? [];
            $contactId = $dealData['CONTACT_ID'] ?? null;
            unset($dealData['PRODUCTS'], $dealData['CONTACT_ID']);

            // Создаем сделку
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['error'])) {
                throw new Exception($result['error_description'] ?? 'Unknown Bitrix24 error');
            }

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

                $productsResponse = $this->client->post($this->webhookUrl . 'crm.deal.productrows.set', [
                    'json' => [
                        'id' => $dealId,
                        'rows' => $formattedProducts
                    ]
                ]);

                $productsResult = json_decode($productsResponse->getBody()->getContents(), true);

                if (isset($productsResult['error'])) {
                    Log::error('Ошибка при добавлении товаров к сделке:', [
                        'deal_id' => $dealId,
                        'error' => $productsResult['error_description'] ?? 'Unknown error',
                        'products' => $formattedProducts
                    ]);
                } else {
                    Log::debug('Товары успешно добавлены к сделке:', [
                        'deal_id' => $dealId,
                        'products_count' => count($formattedProducts)
                    ]);
                }
            }

            // Добавляем контакт к сделке, если указан
            if ($contactId) {
                $contactResponse = $this->client->post($this->webhookUrl . 'crm.deal.contact.add', [
                    'json' => [
                        'id' => $dealId,
                        'fields' => [
                            'CONTACT_ID' => 303,
                            'IS_PRIMARY' => 'Y'
                        ]
                    ]
                ]);

                $contactResult = json_decode($contactResponse->getBody()->getContents(), true);

                if (isset($contactResult['error'])) {
                    Log::error('Ошибка при добавлении контакта к сделке:', [
                        'deal_id' => $dealId,
                        'contact_id' => $contactId,
                        'error' => $contactResult['error_description'] ?? 'Unknown error'
                    ]);
                } else {
                    Log::debug('Контакт успешно добавлен к сделке:', [
                        'deal_id' => $dealId,
                        'contact_id' => $contactId
                    ]);
                }
            }

            return [
                'status' => 'success',
                'deal_id' => $dealId
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
