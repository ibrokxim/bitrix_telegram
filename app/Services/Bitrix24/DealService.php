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
     * Форматирует комментарий для сделки
     */
    protected function formatComment($comment)
    {
        if (empty($comment)) {
            return '';
        }

        try {
            return json_encode(json_decode($comment), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            return $comment;
        }
    }

    /**
     * Создает сделку в Битрикс24
     */
    public function createDeal(array $dealData)
    {
        if (isset($dealData['COMMENTS'])) {
            $dealData['COMMENTS'] = $this->formatComment($dealData['COMMENTS']);
        }

        try {
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return $result['result'];
            }

            Log::error('Ошибка при создании сделки в Битрикс24', [
                'deal_data' => $dealData,
                'response' => $result
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Ошибка при запросе к Битрикс24: ' . $e->getMessage(), [
                'deal_data' => $dealData,
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Обновляет сделку в Битрикс24
     */
    public function updateDeal($dealId, array $dealData)
    {
        if (isset($dealData['COMMENTS'])) {
            $dealData['COMMENTS'] = $this->formatComment($dealData['COMMENTS']);
        }

        try {
            $response = $this->client->post($this->webhookUrl . 'crm.deal.update', [
                'json' => [
                    'id' => $dealId,
                    'fields' => $dealData
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && $result['result']) {
                return true;
            }

            Log::error('Ошибка при обновлении сделки в Битрикс24', [
                'deal_id' => $dealId,
                'deal_data' => $dealData,
                'response' => $result
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Ошибка при запросе к Битрикс24: ' . $e->getMessage(), [
                'deal_id' => $dealId,
                'deal_data' => $dealData,
                'trace' => $e->getTraceAsString()
            ]);

            return false;
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

    /**
     * Получает информацию о сделке по её ID
     */
    public function getDeal($dealId)
    {
        try {
            $response = $this->client->get($this->webhookUrl . 'crm.deal.get', [
                'query' => [
                    'id' => $dealId
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return $result['result'];
            }

            Log::error('Ошибка при получении сделки из Битрикс24', [
                'deal_id' => $dealId,
                'response' => $result
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Ошибка при запросе к Битрикс24: ' . $e->getMessage(), [
                'deal_id' => $dealId,
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Получает список всех стадий сделок
     */
    public function getDealStages()
    {
        try {
            $response = $this->client->get($this->webhookUrl . 'crm.dealcategory.stage.list');
            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                Log::info('Получены стадии сделок:', ['stages' => $result['result']]);
                return $result['result'];
            }

            Log::error('Ошибка при получении стадий сделок', ['response' => $result]);
            return null;

        } catch (\Exception $e) {
            Log::error('Ошибка при запросе стадий сделок: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Получает название стадии по её ID
     */
    public function getStageName($stageId)
    {
        static $stages = null;

        if ($stages === null) {
            $stages = $this->getDealStages();
        }

        // Если стадия найдена в кэше
        if ($stages && isset($stages[$stageId])) {
            return $stages[$stageId]['NAME'];
        }

        // Если это стадия из определенной воронки (формат C5:NEW)
        if (strpos($stageId, ':') !== false) {
            list($category, $stage) = explode(':', $stageId);
            
            try {
                $response = $this->client->get($this->webhookUrl . 'crm.dealcategory.stage.list', [
                    'query' => [
                        'id' => substr($category, 1) // Убираем 'C' из начала
                    ]
                ]);
                
                $result = json_decode($response->getBody()->getContents(), true);
                
                if (isset($result['result'])) {
                    foreach ($result['result'] as $stageInfo) {
                        if ($stageInfo['STATUS_ID'] === $stage) {
                            return $stageInfo['NAME'];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Ошибка при получении названия стадии: ' . $e->getMessage());
            }
        }

        return $stageId; // Возвращаем исходный ID, если не удалось найти название
    }
}
