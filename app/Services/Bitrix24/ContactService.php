<?php

namespace App\Services\Bitrix24;

class ContactService extends Bitrix24BaseService
{
    public function createContact(array $fields)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.contact.add', [
                'json' => [
                    'fields' => $fields,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result'])) {
                return [
                    'status' => 'success',
                    'contact_id' => $result['result']
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to create contact in Bitrix24'
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function checkContactExists($phone)
    {
        try {
            // Нормализуем номер телефона (убираем все кроме цифр)
            $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);

            $response = $this->client->post($this->webhookUrl . 'crm.contact.list', [
                'json' => [
                    'filter' => [
                        'PHONE' => $normalizedPhone
                    ],
                    'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (empty($result['result'])) {
                return [
                    'exists' => false,
                    'contact_id' => null
                ];
            }

            // Проверяем каждый контакт и его телефоны
            foreach ($result['result'] as $contact) {
                if (isset($contact['PHONE']) && is_array($contact['PHONE'])) {
                    foreach ($contact['PHONE'] as $phoneData) {
                        // Нормализуем номер телефона из базы
                        $contactPhone = preg_replace('/[^0-9+]/', '', $phoneData['VALUE']);

                        // Сравниваем нормализованные номера
                        if ($contactPhone === $normalizedPhone) {
                            return [
                                'exists' => true,
                                'contact_id' => $contact['ID'],
                                'contact_data' => [
                                    'name' => $contact['NAME'],
                                    'last_name' => $contact['LAST_NAME'],
                                    'phone' => $phoneData
                                ]
                            ];
                        }
                    }
                }
            }

            return [
                'exists' => false,
                'contact_id' => null
            ];

        } catch (\Exception $e) {
            \Log::error('Error checking contact existence: ' . $e->getMessage(), [
                'phone' => $phone,
                'timestamp' => '2025-02-22 13:10:56',
                'user' => 'ibrokxim'
            ]);

            return [
                'exists' => false,
                'contact_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }
}
