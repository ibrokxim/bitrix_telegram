<?php

namespace App\Services\Bitrix24;

class CompanyService extends Bitrix24BaseService
{
    public function createCompany(array $fields)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.company.add', [
                'json' => [
                    'fields' => $fields,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return [
                    'status' => 'success',
                    'company_id' => $result['result']
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to create company in Bitrix24'
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function checkCompanyExists($inn)
    {
        $response = $this->client->post($this->webhookUrl . 'crm.company.list', [
            'json' => [
                'filter' => ['UF_CRM_1726120575256' => $inn],
                'select' => ['ID', 'ASSIGNED_BY_ID']
            ]
        ]);

        $result = json_decode($response->getBody(), true);

        return [
            'exists' => !empty($result['result']),
            'company_id' => $result['result'][0]['ID'] ?? null,
            'contact_id' => $result['result'][0]['ASSIGNED_BY_ID'] ?? null
        ];
    }

    public function bindContactToCompany($contactId, $companyId, array $additionalFields = [])
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.company.contact.add', [
                'json' => array_merge([
                    'id' => $companyId,
                    'fields' => [
                        'CONTACT_ID' => $contactId
                    ]
                ], $additionalFields)
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return [
                    'status' => 'success',
                    'bind_id' => $result['result']
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to bind contact to company in Bitrix24'
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
