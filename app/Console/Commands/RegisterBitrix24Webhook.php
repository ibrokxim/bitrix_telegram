<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class RegisterBitrix24Webhook extends Command
{
    protected $signature = 'bitrix24:register-webhook';
    protected $description = 'Регистрирует вебхук для отслеживания изменений сделок в Битрикс24';

    public function handle()
    {
        $webhookUrl = config('services.bitrix24.webhook_url');
        $handlerUrl = config('app.url') . '/api/bitrix24/webhook/deal-update';

        try {
            $client = new Client();
            $response = $client->post($webhookUrl . 'event.bind', [
                'json' => [
                    'event' => 'ONCRMDEALUPDATE',
                    'handler' => $handlerUrl,
                    'auth_type' => 'webhook'
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']) && $result['result'] === true) {
                $this->info('Вебхук успешно зарегистрирован!');
                $this->info("URL обработчика: {$handlerUrl}");
                
                Log::info('Вебхук Битрикс24 зарегистрирован', [
                    'handler_url' => $handlerUrl,
                    'result' => $result
                ]);
            } else {
                $this->error('Ошибка при регистрации вебхука');
                $this->error(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                Log::error('Ошибка при регистрации вебхука Битрикс24', [
                    'handler_url' => $handlerUrl,
                    'result' => $result
                ]);
            }

        } catch (\Exception $e) {
            $this->error('Произошла ошибка: ' . $e->getMessage());
            Log::error('Ошибка при регистрации вебхука Битрикс24: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 