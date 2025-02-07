<?php
namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Bitrix24Service
{
    protected $client;
    protected $webhookUrl;
    protected $cacheTimeout = 3600; // 1 час кеширования

    public function __construct()
    {
        $this->client = new Client();
        $this->webhookUrl = env('BITRIX24_WEBHOOK_URL');
    }

    // Получение списка каталогов с кешем
    public function getCatalogs()
    {
        return Cache::remember('catalogs', $this->cacheTimeout, function () {
            $response = $this->client->post($this->webhookUrl . 'catalog.section.list', [
                'json' => [
                    'select' => ['ID', 'IBLOCK_ID', 'NAME'],
                    'filter' => [
                        'iblockId' => 15,
                        'active' => 'Y',
                    ],
                    'order' => ['ID' => 'ASC']
                ]
            ]);
            return json_decode($response->getBody()->getContents(), true)['result'];
        });
    }

    // Получение списка продуктов с кешем
    public function getProducts($sectionId)
    {
        $cacheKey = "products_section_{$sectionId}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($sectionId) {
            try {
                $response = $this->client->post($this->webhookUrl . 'crm.product.list', [
                    'json' => [
                        'select' => [
                            'id', 'NAME', 'CATALOG_ID', 'ACTIVE', 'PRICE',
                            'CURRENCY_ID', 'MEASURE', 'DETAIL_PICTURE',
                            'PROPERTY_FASOVKA_PRICE', 'SECTION_ID'
                        ],
                        'filter' => [
                            'SECTION_ID' => $sectionId,
                            'ACTIVE' => 'Y'
                        ],
                        'order' => ['id' => 'ASC']
                    ]
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if (isset($result['result']) && !empty($result['result'])) {
                    $processedProducts = [];

                    foreach ($result['result'] as $product) {
                        // Кешируем изображения для каждого продукта
                        $product['images'] = $this->getProductImages($product['ID']);
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
        });
    }


    // Получение продукта по ID с кешем
    public function getProductById($id)
    {
        $cacheKey = "product_{$id}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($id) {
            try {
                $response = $this->client->post($this->webhookUrl . 'crm.product.get', [
                    'json' => [
                        'id' => $id,
                    ]
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if (isset($result['result']) && !empty($result['result'])) {
                    $product = $result['result'];
                    $priceData = $this->getProductPrice($product['ID']);

                    return [
                        'id' => $product['ID'],
                        'name' => $product['NAME'],
                        'price' => $priceData ? $priceData['price'] : $product['PRICE'],
                        'currency' => $product['CURRENCY_ID'],
                        'description' => $product['DESCRIPTION'],
                        'description_uz' => $product['PROPERTY_117'],
                        'measure' => 'ml',
                        'catalog_id' => $product['CATALOG_ID'],
                        'images' => $this->getProductImages($product['ID']),
                        'variations' => $this->getProductVariations($id)
                    ];
                }
                return null;
            } catch (\Exception $e) {
                \Log::error('Error getting product: ' . $e->getMessage());
                return null;
            }
        });
    }

    // Кеширование вариаций продукта
    private function getProductVariations($productId)
    {
        $cacheKey = "product_variations_{$productId}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($productId) {
            try {
                $response = $this->client->post($this->webhookUrl . 'catalog.product.offer.list', [
                    'json' => [
                        'select' => [
                            'id', 'iblockId', 'name', 'parentId',
                            'purchasingPrice', 'quantity', 'measure', 'property121'
                        ],
                        'filter' => [
                            'iblockId' => 17,
                            'parentId' => $productId
                        ]
                    ]
                ]);
                $result = json_decode($response->getBody()->getContents(), true);
                Log::info('Variations API Response', ['response' => $result]);

                $variations = [];

                if (isset($result['result']['offers']) && !empty($result['result']['offers'])) {
                    foreach ($result['result']['offers'] as $variation) {
                        $priceData = $this->getProductPrice($variation['id']);
                        $variations[] = [
                            'id' => $variation['id'],
                            'name' => $variation['name'],
                            'size' => $variation['property121']['valueEnum'] ?? null,
                            'price' => $priceData ? $priceData['price'] : null,
                            'quantity' => $variation['quantity'] ?? 0,
                            'measure' => $variation['measure'] ?? 'ml',
                            'parent_id' => $variation['parentId']['value'] ?? null
                        ];
                    }
                }

                return $variations;
            } catch (\Exception $e) {
                \Log::error('Error getting variations: ' . $e->getMessage());
                return [];
            }
        });
    }

    // Метод для очистки кеша
    public function clearCache($sectionId = null, $productId = null)
    {
        if ($sectionId) {
            Cache::forget("products_section_{$sectionId}");
        }
        if ($productId) {
            Cache::forget("product_{$productId}");
            Cache::forget("product_images_{$productId}");
            Cache::forget("product_variations_{$productId}");
        }
        Cache::forget('catalogs');
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
                    'fields' => $dealData,
                    'params' => ['REGISTER_SONET_EVENT' => 'Y']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::debug('Bitrix24 Response:', $result);

            if (isset($result['error'])) {
                throw new \Exception($result['error_description'] ?? 'Unknown Bitrix24 error');
            }

            return [
                'status' => 'success',
                'deal_id' => $result['result']
            ];

        } catch (\Exception $e) {
            Log::error('Bitrix24 Deal Error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function clearProductImagesCache($productId)
    {
        Cache::forget("product_images_{$productId}");
    }

    private function getProductPrice($productId)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'catalog.price.list', [
                'json' => [
                    'filter' => [
                        'productId' => $productId
                    ],
                    'select' => [
                        'id',
                        'currency',
                        'productId',
                        'price'
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // Проверяем наличие цены в ответе
            if (isset($result['result']['prices'][0])) {
                return [
                    'price' => $result['result']['prices'][0]['price'],
                    'currency' => $result['result']['prices'][0]['currency']
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting product price: ' . $e->getMessage(), [
                'productId' => $productId
            ]);
            return null;
        }
    }

    private function getProductImages($productId)
    {
        $cacheKey = "product_images_{$productId}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($productId) {
            try {
                $imageResponse = $this->client->post($this->webhookUrl . 'catalog.productImage.list', [
                    'json' => [
                        'id' => $productId,
                        'productId' => $productId
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

                return $images;
            } catch (\Exception $e) {
                Log::error('Error getting product images: ' . $e->getMessage(), [
                    'productId' => $productId,
                    'trace' => $e->getTraceAsString()
                ]);
                return [];
            }
        });
    }
}
