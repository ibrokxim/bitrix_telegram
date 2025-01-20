<?php
// app/Services/Bitrix24Service.php
namespace App\Services;

use GuzzleHttp\Client;

class Bitrix24Service
{
    protected $client;
    protected $webhookUrl;

    public function __construct()
    {
        $this->client = new Client();
        $this->webhookUrl = env('BITRIX24_WEBHOOK_URL');
        $this->baseUrl = "https://kadyrovmedical.bitrix24.kz"; // Ваш базовый URL
        $this->authToken = "q4a0r1ia6r10uagz"; // Ваш токен аутентификации
    }

    // Получение списка каталогов
    public function getCatalogs()
    {
        $response = $this->client->post($this->webhookUrl . 'catalog.section.list', [
            'json' => [
                'select' => ['ID', 'IBLOCK_ID', 'NAME'],
                'filter' => ['iblockId' => 15,
                    'active' => 'Y',],
                'order' => ['ID' => 'ASC']
            ]
        ]);
        return json_decode($response->getBody()->getContents(), true)['result'];
    }

    // Получение списка продуктов из определенного каталога
    public function getProducts($sectionId)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.product.list', [
                'json' => [
                    'select' => ['id', 'NAME', 'CATALOG_ID','ACTIVE', 'PRICE', 'CURRENCY_ID',
                                 'MEASURE', 'DETAIL_PICTURE', 'PREVIEW_PICTURE',
                                 'PROPERTY_*', 'PROPERTY_FASOVKA_PRICE', 'SECTION_ID'],
                    'filter' => [
                        'CATALOG_ID' => $sectionId,
                    ],
                    'order' => ['id' => 'ASC']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'products' => $result['result'],
                'total' => $result['total'] ?? count($result['result'])
            ];
        } catch (\Exception $e) {
            \Log::error('Bitrix24 API Error: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function getProductById($id)
    {
        $response = $this->client->post($this->webhookUrl . 'crm.product.get', [
            'json' => [
                'id' => $id,
            ]
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (isset($result['result']) && !empty($result['result'])) {
            $product = $result['result'];

            // Обработка изображений и файлов
            $images = [];
            if (isset($product['PROPERTY_45']) && is_array($product['PROPERTY_45'])) {
                foreach ($product['PROPERTY_45'] as $file) {
                    $images[] = [
                        'id' => $file['valueId'],
                        'showUrl' => $this->baseUrl . $file['value']['showUrl'] . '&auth=' . $this->authToken,
                        'downloadUrl' => $this->baseUrl . $file['value']['downloadUrl'] . '&auth=' . $this->authToken,
                    ];
                }
            }

            // Обработка доз и их стоимости
            $doses = [];
            if (isset($product['PROPERTY_FASOVKA']) && is_array($product['PROPERTY_FASOVKA'])) {
                foreach ($product['PROPERTY_FASOVKA'] as $index => $dose) {
                    $doses[] = [
                        'dose' => $dose,
                        'cost' => $product['PROPERTY_FASOVKA_PRICE'][$index] ?? 0
                    ];
                }
            }

            return [
                'id' => $product['ID'],
                'name' => $product['NAME'],
                'price' => $product['PRICE'],
                'currency' => $product['CURRENCY_ID'],
                'description_uz' => $product['PROPERTY_117'],
                'measure' => 'ml',
                'catalog_id' => $product['CATALOG_ID'],
                'images' => $images,
                'doses' => $doses
            ];
        }
        return null;
    }

    public function addOrder($orderData)
    {
        $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
            'json' => $orderData,
        ]);
        return json_decode($response->getBody()->getContents(), true);
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            \Log::error('Bitrix24 Lead Update Error: ' . $e->getMessage());
            return false;
        }
    }

    public function createDeal(array $dealData)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.deal.add', [
                'json' => [
                    'fields' => $dealData
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'deal_id' => $result['result'],
                'status' => 'success'
            ];
        } catch (\Exception $e) {
            \Log::error('Bitrix24 Deal Creation Error: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
