<?php

namespace App\Services\Bitrix24;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WebhookService extends Bitrix24BaseService
{
    protected $client;
    protected $incomingWebhookUrl;

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client();
        $this->incomingWebhookUrl = config('services.bitrix24.incoming_webhook_url');
    }

    /**
     * Регистрирует вебхук для отслеживания изменений сделки
     */
    public function registerDealUpdateWebhook(string $handlerUrl)
    {
        try {
            $response = $this->client->post($this->incomingWebhookUrl . 'event.bind', [
                'json' => [
                    'event' => 'ONCRMDEALUPDATE',
                    'handler' => $handlerUrl,
                    'auth_type' => 'webhook'
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('Вебхук для обновления сделок зарегистрирован', [
                'handler_url' => $handlerUrl,
                'result' => $result
            ]);

            return [
                'status' => 'success',
                'result' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при регистрации вебхука: ' . $e->getMessage(), [
                'handler_url' => $handlerUrl,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Удаляет зарегистрированный вебхук
     */
    public function unregisterWebhook(string $handlerUrl)
    {
        try {
            $response = $this->client->post($this->incomingWebhookUrl . 'event.unbind', [
                'json' => [
                    'event' => 'ONCRMDEALUPDATE',
                    'handler' => $handlerUrl
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('Вебхук удален', [
                'handler_url' => $handlerUrl,
                'result' => $result
            ]);

            return [
                'status' => 'success',
                'result' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при удалении вебхука: ' . $e->getMessage(), [
                'handler_url' => $handlerUrl,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Получает список зарегистрированных вебхуков
     */
    public function getRegisteredWebhooks()
    {
        try {
            $response = $this->client->post($this->incomingWebhookUrl . 'event.get', [
                'json' => [
                    'event' => 'ONCRMDEALUPDATE'
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('Получен список вебхуков', [
                'result' => $result
            ]);

            return [
                'status' => 'success',
                'webhooks' => $result['result'] ?? []
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при получении списка вебхуков: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
} 