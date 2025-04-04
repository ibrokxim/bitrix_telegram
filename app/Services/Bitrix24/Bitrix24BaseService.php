<?php

namespace App\Services\Bitrix24;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class Bitrix24BaseService
{
    protected $client;
    protected $webhookUrl;
    protected $cacheTimeout = 3600;

    public function __construct()
    {
        $this->client = new Client();
        $this->webhookUrl = config('services.bitrix24.webhook_url');
    }

    protected function logError($message, $context = [])
    {
        \Log::error($message, array_merge($context, [
            'timestamp' => '2025-02-22 12:01:04',
            'user' => 'ibrokxim'
        ]));
    }

    /**
     * Получает цену продукта
     *
     * @param int $productId
     * @return array|null
     */
    protected function getProductPrice($productId)
    {
        try {
            $response = $this->client->post($this->webhookUrl . 'crm.product.get', [
                'json' => [
                    'id' => $productId
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && !empty($result['result'])) {
                return [
                    'price' => $result['result']['PRICE'] ?? 0,
                    'currency' => $result['result']['CURRENCY_ID'] ?? 'UZS'
                ];
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error getting product price: ' . $e->getMessage(), [
                'product_id' => $productId
            ]);
            return null;
        }
    }
}
