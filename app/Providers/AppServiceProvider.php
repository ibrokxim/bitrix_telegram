<?php

namespace App\Providers;

use App\Services\Bitrix24\Bitrix24Service;
use App\Services\Bitrix24\CatalogService;
use App\Services\Bitrix24\CompanyService;
use App\Services\Bitrix24\ContactService;
use App\Services\Bitrix24\DealService;
use App\Services\Bitrix24\LegalEntityService;
use App\Services\Bitrix24\ProductService;
use App\Services\ErrorHandlerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Bitrix24Service::class, function ($app) {
            return new Bitrix24Service(
                $app->make(CatalogService::class),
                $app->make(ProductService::class),
                $app->make(ContactService::class),
                $app->make(CompanyService::class),
                $app->make(DealService::class),
                $app->make(LegalEntityService::class)
            );
        });
        $this->app->singleton(ErrorHandlerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
