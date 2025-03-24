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
                $productsResponse = $this->client->post($this->webhookUrl . 'crm.deal.productrows.set', [
                    'json' => [
                        'id' => $dealId,
                        'rows' => $products
                    ]
                ]);

                $productsResult = json_decode($productsResponse->getBody()->getContents(), true);
                Log::debug('Bitrix24 Products Response:', $productsResult);
            }

            return [
                'status' => 'success',
                'deal_id' => $dealId
            ];

        } catch (Exception $e) {
            Log::error('Bitrix24 Deal Error: ' . $e->getMessage());
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
