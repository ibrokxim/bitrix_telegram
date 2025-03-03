<?php

namespace App\Services\Bitrix24;


use Exception;
use Illuminate\Support\Facades\Log;

class DealService extends Bitrix24BaseService
{
    public function createDeal(array $dealData)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData,
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::debug('Bitrix24 Response:', $result);

            if (isset($result['error'])) {
                throw new Exception($result['error_description'] ?? 'Unknown Bitrix24 error');
            }

            return [
                'status' => 'success',
                'deal_id' => $result['result']
            ];

        } catch (Exception $e) {
            Log::error('Bitrix24 Deal Error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    public function createLead(array $leadData)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.contact.add', [
                'json' => [
                    'fields' => $leadData, // Данные контакта
                    'params' => ['REGISTER_SONET_EVENT' => 'Y'] // Дополнительные параметры
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'contact_id' => $result['result'], // ID созданного контакта
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
