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
     * Создает новый лид в Битрикс24
     *
     * @param array $leadData Данные лида
     * @return array Результат создания лида
     */
    public function createLead(array $leadData)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.lead.add', [
                'json' => [
                    'fields' => $leadData,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return [
                    'status' => 'success',
                    'lead_id' => $result['result']
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to create lead in Bitrix24'
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при создании лида в Bitrix24: ' . $e->getMessage(), [
                'lead_data' => $leadData
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Обновляет статус лида в Битрикс24
     *
     * @param int $leadId ID лида
     * @param string $status Новый статус
     * @return array Результат обновления статуса
     */
    public function updateLeadStatus($leadId, $status)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.lead.update', [
                'json' => [
                    'id' => $leadId,
                    'fields' => [
                        'STATUS_ID' => $status
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && $result['result']) {
                return [
                    'status' => 'success',
                    'lead_id' => $leadId
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to update lead status in Bitrix24'
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении статуса лида в Bitrix24: ' . $e->getMessage(), [
                'lead_id' => $leadId,
                'status' => $status
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
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
            // Извлекаем товары из данных сделки
            $products = $dealData['PRODUCT_ROWS'] ?? [];
            unset($dealData['PRODUCT_ROWS']);

            // Создаем сделку
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!isset($result['result'])) {
                throw new Exception('Failed to create deal: ' . json_encode($result));
            }

            $dealId = $result['result'];

            // Если есть товары, добавляем их к сделке
            if (!empty($products)) {
                $response = $this->client->post($this->webhookUrl . 'crm.deal.productrows.set', [
                    'json' => [
                        'id' => $dealId,
                        'rows' => $products
                    ]
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if (!isset($result['result'])) {
                    Log::error('Ошибка при добавлении товаров к сделке:', [
                        'deal_id' => $dealId,
                        'products' => $products,
                        'response' => $result
                    ]);
                }
            }

            Log::info('Сделка успешно создана в Битрикс24', [
                'deal_id' => $dealId,
                'fields' => $dealData,
                'products' => $products
            ]);

            return [
                'status' => 'success',
                'deal_id' => $dealId
            ];

        } catch (Exception $e) {
            Log::error('Ошибка при создании сделки в Bitrix24: ' . $e->getMessage(), [
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
     * Получает список товаров с ценой больше нуля
     */
    public function getAvailableProducts()
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.product.list', [
                'json' => [
                    'filter' => [
                        '>PRICE' => 0
                    ],
                    'select' => ['ID', 'NAME', 'PRICE', 'CURRENCY_ID']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!isset($result['result'])) {
                throw new Exception('Не удалось получить список товаров');
            }

            return [
                'status' => 'success',
                'products' => $result['result']
            ];

        } catch (Exception $e) {
            Log::error('Ошибка при получении списка товаров: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Создает сделку с товарами
     */
    public function createDealWithProducts(array $dealData, array $products)
    {
        try {
            // Создаем сделку
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!isset($result['result'])) {
                throw new Exception('Не удалось создать сделку');
            }

            $dealId = $result['result'];

            // Добавляем товары к сделке
            $response = $this->client->post($this->webhookUrl . 'crm.deal.productrows.set', [
                'json' => [
                    'id' => $dealId,
                    'rows' => $products
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!isset($result['result'])) {
                throw new Exception('Не удалось добавить товары к сделке');
            }

            return [
                'status' => 'success',
                'deal_id' => $dealId
            ];

        } catch (Exception $e) {
            Log::error('Ошибка при создании сделки с товарами: ' . $e->getMessage(), [
                'deal_data' => $dealData,
                'products' => $products
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Форматирует товар для добавления к сделке
     */
    public function formatProductRow(array $product, array $options = [])
    {
        $row = [
            'PRODUCT_ID' => $product['ID'],
            'QUANTITY' => $options['quantity'] ?? 1
        ];

        // Базовая цена
        if (isset($options['price_exclusive'])) {
            $row['PRICE_EXCLUSIVE'] = $options['price_exclusive'];
        } else {
            $row['PRICE'] = $options['price'] ?? $product['PRICE'];
        }

        // Налог
        if (isset($options['tax_rate'])) {
            $row['TAX_RATE'] = $options['tax_rate'];
            $row['TAX_INCLUDED'] = $options['tax_included'] ?? 'N';
        }

        // Скидка
        if (isset($options['discount_sum'])) {
            $row['DISCOUNT_SUM'] = $options['discount_sum'];
            $row['DISCOUNT_TYPE_ID'] = 1; // фиксированная сумма
        } elseif (isset($options['discount_rate'])) {
            $row['DISCOUNT_RATE'] = $options['discount_rate'];
            $row['DISCOUNT_TYPE_ID'] = 2; // процент
        }

        return $row;
    }
}
