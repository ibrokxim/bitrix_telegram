<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Cache;

class CatalogService extends Bitrix24BaseService
{
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
