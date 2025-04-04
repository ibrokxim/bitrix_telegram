<?php
namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
                \Log::error('Error getting variations: ' . $e->getMessage());
                return [];
            }
        });
    }

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

    public function createDeal(array $dealData)
    {
        try {
            return $this->dealService->createDeal($dealData);
        } catch (\Exception $e) {
            Log::error('Error in createDeal: ' . $e->getMessage(), [
                'data' => $dealData,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
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
        } catch (Exception $e) {
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

    public function checkCompanyExists($inn)
    {
        $response = $this->client->post($this->webhookUrl . 'crm.company.list', [
            'json' => [
                'filter' => ['UF_CRM_INN' => $inn],
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

    public function checkContactExists($phone)
    {
        $response = $this->client->post($this->webhookUrl . 'crm.contact.list', [
            'json' => [
                'filter' => ['PHONE' => $phone],
                'select' => ['ID']
            ]
        ]);

        $result = json_decode($response->getBody(), true);

        return [
            'exists' => !empty($result['result']),
            'contact_id' => $result['result'][0]['ID'] ?? null
        ];
    }

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
