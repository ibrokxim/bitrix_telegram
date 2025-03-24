<?php

namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Log;

class DealService extends Bitrix24BaseService
{
    public function createDeal(array $dealData)
    {
        try {
            // Отдельно обрабатываем товары
            $products = $dealData['PRODUCTS'] ?? [];
            unset($dealData['PRODUCTS']);

            // Создаем сделку
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::debug('Bitrix24 Deal Response:', $result);

            if (isset($result['error'])) {
                throw new Exception($result['error_description'] ?? 'Unknown Bitrix24 error');
            }

            $dealId = $result['result'];

            // Если есть товары, добавляем их к сделке
            if (!empty($products)) {
                // Форматируем товары для Битрикс24
                $formattedProducts = array_map(function ($product) {
                    return [
                        'PRODUCT_NAME' => $product['name'],
                        'PRICE' => (float)$product['price'],
                        'QUANTITY' => (float)$product['quantity'],
                        'MEASURE_CODE' => 796, // Код единицы измерения (шт)
                        'MEASURE_NAME' => 'шт',
                        'TAX_INCLUDED' => 'N', // Налог не включен
                        'TAX_RATE' => 0, // Ставка налога
                        'CURRENCY_ID' => 'UZS',
                        // Расчет цен
                        'PRICE_EXCLUSIVE' => (float)$product['price'], // Цена без налогов
                        'PRICE_NETTO' => (float)$product['price'], // Цена без скидок и налогов
                        'PRICE_BRUTTO' => (float)$product['price'], // Цена с налогами
                        // Скидки
                        'DISCOUNT_TYPE_ID' => 2, // Процентная скидка
                        'DISCOUNT_RATE' => 0, // Процент скидки
                        'DISCOUNT_SUM' => 0, // Сумма скидки
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

            return [
                'status' => 'success',
                'deal_id' => $dealId
            ];

        } catch (Exception $e) {
            Log::error('Bitrix24 Deal Error: ' . $e->getMessage(), [
                'deal_data' => $dealData,
                'products' => $products ?? []
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Получение информации о сделке
     */
    public function getDeal($dealId)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.deal.get', [
                'json' => [
                    'id' => $dealId
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['error'])) {
                throw new Exception($result['error_description'] ?? 'Unknown Bitrix24 error');
            }

            return $result['result'] ?? null;

        } catch (Exception $e) {
            Log::error('Ошибка при получении информации о сделке: ' . $e->getMessage(), [
                'deal_id' => $dealId
            ]);
            return null;
        }
    }

    public function createLead(array $leadData)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.contact.add', [
                'json' => [
                    'fields' => $leadData,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'contact_id' => $result['result'],
                'status' => 'success'
            ];
        } catch (Exception $e) {
            \Log::error('Bitrix24 Contact Creation Error: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function updateLeadStatus($leadId, $status)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.lead.update', [
                'json' => [
                    'ID' => $leadId,
                    'FIELDS' => [
                        'STATUS_ID' => $status
                    ]
                ]
            ]);

            return true;
        } catch (Exception $e) {
            \Log::error('Bitrix24 Lead Update Error: ' . $e->getMessage());
            return false;
        }
    }
}
