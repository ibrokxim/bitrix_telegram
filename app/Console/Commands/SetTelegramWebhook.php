<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';
    protected $description = 'Установить вебхук для Telegram бота';

    public function handle()
    {
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));

        $response = $telegram->setWebhook([
            'url' => 'https://api.kadyrovclinic.uz/webhook/telegram'
        ]);

        if ($response) {
            $this->info('Webhook успешно установлен!');
        } else {
            $this->error('Ошибка при установке вебхука.');
        }
    }
}
