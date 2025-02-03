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
                    'select' => ['id', 'NAME', 'CATALOG_ID', 'ACTIVE', 'PRICE', 'CURRENCY_ID',
                        'MEASURE', 'DETAIL_PICTURE',
                         'PROPERTY_FASOVKA_PRICE', 'SECTION_ID'],
                    'filter' => [
                        'CATALOG_ID' => $sectionId,
                    ],
                    'order' => ['id' => 'ASC']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && !empty($result['result'])) {
                $processedProducts = [];

                foreach ($result['result'] as $product) {
                    try {
                        $imageResponse = $this->client->post($this->webhookUrl . 'catalog.productImage.list', [
                            'json' => [
                                'id' => $product['ID'],
                                'productId' => $product['ID']
                            ]
                        ]);

                        $imageResult = json_decode($imageResponse->getBody()->getContents(), true);
                        $images = [];

                        if (isset($imageResult['result']['productImages']) && !empty($imageResult['result']['productImages'])) {
                            foreach ($imageResult['result']['productImages'] as $image) {
                                $images[] = [
                                    'id' => $image['id'],
                                    'name' => $image['name'],
                                    'detailUrl' => $image['detailUrl'],
                                    'downloadUrl' => $image['downloadUrl'],
                                    'type' => $image['type'],
                                    'createTime' => $image['createTime']
                                ];
                            }
                        }
                        $product['images'] = $images;

                    } catch (\Exception $e) {
                        \Log::error('Error getting images for product: ' . $e->getMessage(), [
                            'productId' => $product['ID']
                        ]);
                        $product['images'] = [];
                    }

                    $processedProducts[] = $product;
                }

                return [
                    'products' => $processedProducts,
                    'total' => $result['total'] ?? count($processedProducts)
                ];
            }

            return [
                'products' => [],
                'total' => 0
            ];

        } catch (\Exception $e) {
            \Log::error('Bitrix24 API Error: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }


    private function getProductVariations($productId)
    {
        try {
            \Log::info('Searching variations for product ID: ' . $productId);

            $response = $this->client->post($this->webhookUrl . 'catalog.product.offer.list', [
                'json' => [
                    'select' => [
                        'id',
                        'iblockId',
                        'name',
                        'parentId',
                        'property121',
                        'quantity',
                        'measure'
                    ],
                    'filter' => [
                        'iblockId' => 17,
                        'parentId' => $productId
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            \Log::info('Variations API Response:', $result);

            $variations = [];

            // Изменено: проверяем наличие offers в результате
            if (isset($result['result']['offers']) && !empty($result['result']['offers'])) {
                foreach ($result['result']['offers'] as $variation) { // Изменено: перебираем offers
                    $variations[] = [
                        'id' => $variation['id'],
                        'name' => $variation['name'],
                        'size' => $variation['property121']['valueEnum'] ?? null,
                        'price' => $variation['property121']['value'] ?? 0,
                        'quantity' => $variation['quantity'] ?? 0,
                        'measure' => $variation['measure'] ?? 'ml',
                        'parent_id' => $variation['parentId']['value'] ?? null
                    ];
                }
            }

            return $variations;
        } catch (\Exception $e) {
            \Log::error('Error getting variations: ' . $e->getMessage(), [
                'productId' => $productId,
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    private function processImages($product)
    {
        try {
            // Запрос к catalog.productImage.list
            $response = $this->client->post($this->webhookUrl . 'catalog.productImage.list', [
                'json' => [
                    'id' => $product['ID'],
                    'productId' => $product['ID']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $images = [];

            if (isset($result['result']['productImages']) && !empty($result['result']['productImages'])) {
                foreach ($result['result']['productImages'] as $image) {
                    $images[] = [
                        'id' => $image['id'],
                        'name' => $image['name'],
                        'detailUrl' => $image['detailUrl'],
                        'downloadUrl' => $image['downloadUrl'],
                        'type' => $image['type'],
                        'createTime' => $image['createTime']
                    ];
                }
            }

            return $images;
        } catch (\Exception $e) {
            \Log::error('Error getting product images: ' . $e->getMessage(), [
                'productId' => $product['ID'],
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    public function getProductById($id)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.product.get', [
                'json' => [
                    'id' => $id,
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && !empty($result['result'])) {
                $product = $result['result'];

                // Обработка изображений
                $images = $this->processImages($product);

                $variations = $this->getProductVariations($id);

                return [
                    'id' => $product['ID'],
                    'name' => $product['NAME'],
                    'price' => $product['PRICE'],
                    'currency' => $product['CURRENCY_ID'],
                    'description_uz' => $product['PROPERTY_117'],
                    'measure' => 'ml',
                    'catalog_id' => $product['CATALOG_ID'],
                    'images' => $images,
                    'variations' => $variations,

                ];
            }
            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting product: ' . $e->getMessage(), [
                'productId' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
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
