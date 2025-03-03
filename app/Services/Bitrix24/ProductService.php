<?php
namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductService extends Bitrix24BaseService
{
    public function getProducts($sectionId)
    {
        $cacheKey = "products_section_{$sectionId}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($sectionId) {
            try {
                $response = $this->client->post($this->webhookUrl . 'crm.product.list', [
                    'json' => [
                        'select' => [
                            'id', 'NAME', 'CATALOG_ID', 'PRICE',
                            'CURRENCY_ID', 'MEASURE', 'SECTION_ID'
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

            } catch (Exception $e) {
                \Log::error('Bitrix24 API Error: ' . $e->getMessage());
                return [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        });
    }

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
            } catch (Exception $e) {
                \Log::error('Error getting product: ' . $e->getMessage());
                return null;
            }
        });
    }

    private function getProductImages($productId)
    {
        $cacheKey = "product_images_{$productId}";

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($productId) {
            try {
                $images = [];

                // Получаем изображения головного товара
                $mainImageResponse = $this->client->post($this->webhookUrl . 'catalog.productImage.list', [
                    'json' => [
                        'productId' => $productId,
                        'select' => [
                            'id',
                            'name',
                            'productId',
                            'type',
                            'createTime',
                            'downloadUrl',
                            'detailUrl'
                        ]
                    ]
                ]);

                $mainImageResult = json_decode($mainImageResponse->getBody()->getContents(), true);

                // Получаем вариации товара
                $variationsResponse = $this->client->post($this->webhookUrl . 'catalog.product.offer.list', [
                    'json' => [
                        'select' => [
                            'id',
                            'iblockId',
                            'name',
                            'parentId',
                            'property121' // Размер/фасовка
                        ],
                        'filter' => [
                            'iblockId' => 17,
                            'parentId' => $productId
                        ]
                    ]
                ]);

                $variationsResult = json_decode($variationsResponse->getBody()->getContents(), true);

                // Если есть вариации, получаем их изображения
                if (isset($variationsResult['result']['offers']) && !empty($variationsResult['result']['offers'])) {
                    foreach ($variationsResult['result']['offers'] as $variation) {
                        $variationImageResponse = $this->client->post($this->webhookUrl . 'catalog.productImage.list', [
                            'json' => [
                                'productId' => $variation['id'],
                                'select' => [
                                    'id',
                                    'name',
                                    'productId',
                                    'type',
                                    'createTime',
                                    'downloadUrl',
                                    'detailUrl'
                                ]
                            ]
                        ]);

                        $variationImageResult = json_decode($variationImageResponse->getBody()->getContents(), true);

                        if (isset($variationImageResult['result']['productImages'])) {
                            foreach ($variationImageResult['result']['productImages'] as $image) {
                                $images[] = [
                                    'id' => $image['id'],
                                    'name' => $image['name'],
                                    'detailUrl' => $image['detailUrl'],
                                    'downloadUrl' => $image['downloadUrl'],
                                    'type' => $image['type'],
                                    'createTime' => $image['createTime'],
                                    'size' => $variation['property121']['valueEnum'] ?? null,
                                    'variation_id' => $variation['id'],
                                    'is_variation' => true
                                ];
                            }
                        }
                    }
                }

                // Если нет вариаций или есть, но у них нет изображений, используем изображения головного товара
                if (empty($images) && isset($mainImageResult['result']['productImages'])) {
                    foreach ($mainImageResult['result']['productImages'] as $image) {
                        $images[] = [
                            'id' => $image['id'],
                            'name' => $image['name'],
                            'detailUrl' => $image['detailUrl'],
                            'downloadUrl' => $image['downloadUrl'],
                            'type' => $image['type'],
                            'createTime' => $image['createTime'],
                            'is_variation' => false
                        ];
                    }
                }

                // Логируем результат
                Log::info('Product images fetched', [
                    'productId' => $productId,
                    'imagesCount' => count($images),
                    'hasVariations' => isset($variationsResult['result']['offers'])
                ]);

                return $images;

            } catch (Exception $e) {
                Log::error('Error getting product images: ' . $e->getMessage(), [
                    'productId' => $productId,
                    'trace' => $e->getTraceAsString()
                ]);
                return [];
            }
        });
    }


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
            } catch (Exception $e) {
                \Log::error('Error getting variations: ' . $e->getMessage());
                return [];
            }
        });
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
        } catch (Exception $e) {
            Log::error('Error getting product price: ' . $e->getMessage(), [
                'productId' => $productId
            ]);
            return null;
        }
    }

}
