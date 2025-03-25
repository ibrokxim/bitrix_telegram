<?php

namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Log;

class DealService extends Bitrix24BaseService
{
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
