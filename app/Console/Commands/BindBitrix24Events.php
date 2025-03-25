<?php

namespace App\Console\Commands;

use App\Services\Bitrix24\DealService;
use Illuminate\Console\Command;

class BindBitrix24Events extends Command
{
    protected $signature = 'bitrix24:bind-events';
    protected $description = 'Привязывает обработчики событий Bitrix24';

    protected $dealService;

    public function __construct(DealService $dealService)
    {
        parent::__construct();
        $this->dealService = $dealService;
    }

    public function handle()
    {
        $this->info('Привязка событий Bitrix24...');

        try {
            $result = $this->dealService->bindDealUpdateEvent();
            $this->info('Событие успешно привязано!');
            $this->info('Результат: ' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $this->error('Ошибка при привязке события: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
} 