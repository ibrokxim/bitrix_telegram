<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Bitrix24\Bitrix24Service;
use App\Services\Bitrix24\CatalogService;
use App\Services\Bitrix24\ProductService;
use App\Services\Bitrix24\ContactService;
use App\Services\Bitrix24\CompanyService;
use App\Services\Bitrix24\DealService;
use App\Services\Bitrix24\LegalEntityService;
use GuzzleHttp\Client;
use App\Services\ErrorHandlerService;
use App\Models\User;
use App\Observers\UserObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Bitrix24Service::class, function ($app) {
            $webhookUrl = config('services.bitrix24.webhook_url');
            $client = new Client([
                'timeout' => 30,
                'verify' => false // только для разработки, уберите в продакшене
            ]);

            return new Bitrix24Service(
                $app->make(CatalogService::class),
                $app->make(ProductService::class),
                $app->make(ContactService::class),
                $app->make(CompanyService::class),
                $app->make(DealService::class),
                $app->make(LegalEntityService::class),
                $client,
                $webhookUrl
            );
        });
        $this->app->singleton(ErrorHandlerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
    }
}
