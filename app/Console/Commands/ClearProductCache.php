<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClearProductCache extends Command
{
    protected $signature = 'cache:clear-products {--section=} {--product=} {--all}';
    protected $description = 'Clear products cache';

    protected $cacheKeys = [
        'catalogs',
        'catalog_prices',
        'products_all',
    ];

    public function handle()
    {
        try {
            if ($this->option('all')) {
                $this->clearAllCache();
                return;
            }

            if ($sectionId = $this->option('section')) {
                $this->clearSectionCache($sectionId);
            }

            if ($productId = $this->option('product')) {
                $this->clearProductCache($productId);
            }

            if (!$sectionId && !$productId && !$this->option('all')) {
                if (!$this->confirm('Вы собираетесь очистить весь кеш. Продолжить?')) {
                    return;
                }
                $this->clearAllCache();
            }

        } catch (\Exception $e) {
            Log::error('Error clearing cache: ' . $e->getMessage());
            $this->error('Произошла ошибка при очистке кеша: ' . $e->getMessage());
        }
    }

    /**
     * Очистка кеша для конкретного раздела
     */
    protected function clearSectionCache($sectionId)
    {
        try {
            Cache::forget("products_section_{$sectionId}");
            // Очищаем связанные кеши
            Cache::forget("section_products_{$sectionId}");
            Cache::forget("section_info_{$sectionId}");

            $this->info("✅ Кеш очищен для раздела {$sectionId}");
            Log::info("Cache cleared for section {$sectionId}");
        } catch (\Exception $e) {
            $this->error("❌ Ошибка при очистке кеша раздела {$sectionId}: " . $e->getMessage());
            Log::error("Error clearing section cache: " . $e->getMessage());
        }
    }

    /**
     * Очистка кеша для конкретного продукта
     */
    protected function clearProductCache($productId)
    {
        try {
            $keysToForget = [
                "product_{$productId}",
                "product_images_{$productId}",
                "product_variations_{$productId}",
                "product_price_{$productId}",
            ];

            foreach ($keysToForget as $key) {
                Cache::forget($key);
            }

            $this->info("✅ Кеш очищен для продукта {$productId}");
            Log::info("Cache cleared for product {$productId}");
        } catch (\Exception $e) {
            $this->error("❌ Ошибка при очистке кеша продукта {$productId}: " . $e->getMessage());
            Log::error("Error clearing product cache: " . $e->getMessage());
        }
    }

    /**
     * Полная очистка кеша
     */
    protected function clearAllCache()
    {
        try {
            // Очищаем все известные ключи кеша
            foreach ($this->cacheKeys as $key) {
                Cache::forget($key);
            }

            // Очищаем кеш для всех продуктов в базе данных
            if (class_exists('\App\Models\Product')) {
                $products = \App\Models\Product::pluck('id')->toArray();
                foreach ($products as $productId) {
                    $this->clearProductCache($productId);
                }
            }

            // Принудительная очистка всего кеша
            Cache::flush();

            $this->info('✅ Весь кеш продуктов успешно очищен');
            Log::info('All product cache cleared');
        } catch (\Exception $e) {
            $this->error('❌ Ошибка при очистке всего кеша: ' . $e->getMessage());
            Log::error('Error clearing all cache: ' . $e->getMessage());
        }
    }
}
