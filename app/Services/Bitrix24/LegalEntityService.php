<?php

namespace App\Services\Bitrix24;

use Exception;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;

class LegalEntityService extends Bitrix24BaseService
{
    private $companyService;
    private $contactService;

    public function __construct(CompanyService $companyService, ContactService $contactService)
    {
        parent::__construct();
        $this->companyService = $companyService;
        $this->contactService = $contactService;
    }

    public function createLegalEntity(array $data)
    {
        try {
            // Создаем промисы для параллельных запросов
            $promises = [
                'company' => $this->client->postAsync($this->webhookUrl . 'crm.company.add', [
                    'json' => [
                        'fields' => [
                            'TITLE' => $data['company_name'],
                            'COMPANY_TYPE' => 'CUSTOMER', // тип компании
                            'INDUSTRY' => $data['industry'] ?? '',
                            'BANKING_DETAILS' => $data['banking_details'] ?? '',
                            'ADDRESS' => $data['company_address'] ?? '',
                            'ADDRESS_LEGAL' => $data['legal_address'] ?? '',
                            'INN' => $data['inn'] ?? '',
                            'KPP' => $data['kpp'] ?? '',
                            'PHONE' => [['VALUE' => $data['phone'], 'VALUE_TYPE' => 'WORK']],
                            'EMAIL' => [['VALUE' => $data['email'], 'VALUE_TYPE' => 'WORK']],
                            'COMMENTS' => $data['comments'] ?? '',
                            'DATE_CREATE' => '2025-02-18 09:01:15',
                            'CREATED_BY_ID' => 1, // ID создателя
                        ],
                        'params' => ['REGISTER_SONET_EVENT' => 'Y']
                    ]
                ]),
                'contact' => $this->client->postAsync($this->webhookUrl . 'crm.contact.add', [
                    'json' => [
                        'fields' => [
                            'NAME' => $data['contact_name'],
                            'LAST_NAME' => $data['contact_lastname'] ?? '',
                            'SECOND_NAME' => $data['contact_middlename'] ?? '',
                            'POST' => $data['position'] ?? '', // должность
                            'PHONE' => [['VALUE' => $data['contact_phone'], 'VALUE_TYPE' => 'WORK']],
                            'EMAIL' => [['VALUE' => $data['contact_email'], 'VALUE_TYPE' => 'WORK']],
                            'TYPE_ID' => 'CLIENT',
                            'SOURCE_ID' => $data['source_id'] ?? 'SELF',
                            'DATE_CREATE' => '2025-02-18 09:01:15',
                            'CREATED_BY_ID' => 1,
                        ],
                        'params' => ['REGISTER_SONET_EVENT' => 'Y']
                    ]
                ])
            ];

            // Выполняем запросы параллельно
            $responses = Utils::unwrap($promises);

            // Получаем результаты
            $companyResult = json_decode($responses['company']->getBody()->getContents(), true);
            $contactResult = json_decode($responses['contact']->getBody()->getContents(), true);

            if (!isset($companyResult['result']) || !isset($contactResult['result'])) {
                throw new Exception('Failed to create company or contact');
            }

            $companyId = $companyResult['result'];
            $contactId = $contactResult['result'];

            // Связываем компанию и контакт
            $bindResponse = $this->client->post($this->webhookUrl . 'crm.company.contact.add', [
                'json' => [
                    'id' => $companyId,
                    'fields' => [
                        'CONTACT_ID' => $contactId,
                        'IS_PRIMARY' => 'Y', // Устанавливаем как основной контакт
                        'SORT' => 10
                    ]
                ]
            ]);

            $bindResult = json_decode($bindResponse->getBody()->getContents(), true);

            // Логируем успешное создание
            Log::info('Legal entity created successfully', [
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'timestamp' => '2025-02-18 09:01:15',
                'user' => 'ibrokxim'
            ]);

            return [
                'status' => 'success',
                'data' => [
                    'company_id' => $companyId,
                    'contact_id' => $contactId,
                    'bind_result' => $bindResult['result'] ?? null
                ]
            ];

        } catch (Exception $e) {
            Log::error('Error creating legal entity: ' . $e->getMessage(), [
                'data' => $data,
                'timestamp' => '2025-02-18 09:01:15',
                'user' => 'ibrokxim',
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
