<?php
namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

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

    // Кеширование изображений продукта
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
                \Log::error('Error getting images: ' . $e->getMessage());
                return [];
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

                    return [
                        'id' => $product['ID'],
                        'name' => $product['NAME'],
                        'price' => $product['PRICE'],
                        'currency' => $product['CURRENCY_ID'],
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
                            'property121', 'quantity', 'measure'
                        ],
                        'filter' => [
                            'iblockId' => 17,
                            'parentId' => $productId
                        ]
                    ]
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                $variations = [];

                if (isset($result['result']['offers']) && !empty($result['result']['offers'])) {
                    foreach ($result['result']['offers'] as $variation) {
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
}
