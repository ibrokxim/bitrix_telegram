<?php

namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class DealService extends Bitrix24BaseService
{
    public function __construct()
    {
        $this->webhookUrl = config('services.bitrix24.webhook_url');
        $this->client = new Client();
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

            if (!$response->getStatusCode() === 200) {
                throw new Exception('Failed to create deal: ' . $response->getBody()->getContents());
            }

            $result = json_decode($response->getBody()->getContents(), true);
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

                if (!$productsResponse->getStatusCode() === 200) {
                    Log::error('Ошибка при добавлении товаров к сделке:', [
                        'deal_id' => $dealId,
                        'error' => $productsResponse->getBody()->getContents(),
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
                $contactResponse = $this->client->post($this->webhookUrl . 'crm.deal.contact.add', [
                    'json' => [
                        'id' => $dealId,
                        'fields' => [
                            'CONTACT_ID' => $contactId,
                            'IS_PRIMARY' => 'Y'
                        ]
                    ]
                ]);

                if (!$contactResponse->getStatusCode() === 200) {
                    Log::error('Ошибка при добавлении контакта к сделке:', [
                        'deal_id' => $dealId,
                        'contact_id' => $contactId,
                        'error' => $contactResponse->getBody()->getContents()
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
                'fields' => $dealData
            ]);

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

    /**
     * Получает информацию о сделке по её ID
     *
     * @param string|int $dealId ID сделки
     * @return array|null
     */
    public function getDeal($dealId)
    {
        try {
            $response = $this->bitrix24->request('crm.deal.get', [
                'id' => $dealId
            ]);

            if (isset($response['result'])) {
                return $response['result'];
            }

            Log::error('Не удалось получить данные сделки', [
                'deal_id' => $dealId,
                'response' => $response
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ошибка при получении данных сделки: ' . $e->getMessage(), [
                'deal_id' => $dealId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
