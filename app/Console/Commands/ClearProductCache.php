<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearProductCache extends Command
{
    protected $signature = 'cache:clear-products {--section=} {--product=}';
    protected $description = 'Clear products cache';

    public function handle()
    {
        if ($sectionId = $this->option('section')) {
            Cache::forget("products_section_{$sectionId}");
            $this->info("Cache cleared for section {$sectionId}");
        }

        if ($productId = $this->option('product')) {
            Cache::forget("product_{$productId}");
            Cache::forget("product_images_{$productId}");
            Cache::forget("product_variations_{$productId}");
            $this->info("Cache cleared for product {$productId}");
        }

        if (!$sectionId && !$productId) {
            Cache::tags(['products', 'catalogs'])->flush();
            $this->info('All product cache cleared');
        }
    }
}
