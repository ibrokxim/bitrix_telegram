<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBitrixUsers extends Command
{
    protected $signature = 'bitrix:check-user {phone : Номер телефона для проверки}';
    protected $description = 'Проверка пользователя в Битрикс24 по номеру телефона';

    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        parent::__construct();
        $this->bitrix24Service = $bitrix24Service;
    }

    public function handle()
    {
        $phone = $this->argument('phone');
        $this->info('Проверяем пользователя с номером: ' . $phone);

        try {
            $result = $this->bitrix24Service->findUserByPhone($phone);

            if ($result) {
                $this->info('Пользователь найден в Битрикс24!');
                $this->info('Найден по номеру: ' . $result['found_by']);
                
                if ($result['type'] === 'contact') {
                    $this->info('Тип: Контакт');
                    $this->info('Имя: ' . ($result['data']['NAME'] ?? 'Не указано'));
                    $this->info('Фамилия: ' . ($result['data']['LAST_NAME'] ?? 'Не указано'));
                    if (!empty($result['data']['PHONE'])) {
                        $this->info('Телефоны в Битрикс24:');
                        foreach ($result['data']['PHONE'] as $phone) {
                            $this->info('- ' . $phone['VALUE']);
                        }
                    }
                } else {
                    $this->info('Тип: Компания');
                    $this->info('Название: ' . ($result['data']['TITLE'] ?? 'Не указано'));
                    $this->info('ИНН: ' . ($result['data']['UF_CRM_1708963492'] ?? 'Не указано'));
                    if (!empty($result['data']['PHONE'])) {
                        $this->info('Телефоны в Битрикс24:');
                        foreach ($result['data']['PHONE'] as $phone) {
                            $this->info('- ' . $phone['VALUE']);
                        }
                    }
                }

                return 0;
            }

            $this->warn('Пользователь не найден в Битрикс24');
            return 1;

        } catch (\Exception $e) {
            $this->error('Ошибка при проверке: ' . $e->getMessage());
            Log::error('Ошибка при проверке пользователя в Битрикс24: ' . $e->getMessage());
            return 1;
        }
    }
} 