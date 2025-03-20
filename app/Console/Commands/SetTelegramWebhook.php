<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api as TelegramBot;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';
    protected $description = 'Set Telegram bot webhook URL';

    public function handle()
    {
        try {
            $bot = new TelegramBot(config('services.telegram.bot_token'));
            
            // Устанавливаем вебхук
            $webhookUrl = 'https://api.kadyrovapp.uz/api/webhook/telegram';
            $response = $bot->setWebhook(['url' => $webhookUrl]);

            if ($response) {
                $this->info('Вебхук успешно установлен!');
                $this->info('URL: ' . $webhookUrl);
            } else {
                $this->error('Не удалось установить вебхук.');
            }
        } catch (\Exception $e) {
            $this->error('Ошибка: ' . $e->getMessage());
        }
    }
}
