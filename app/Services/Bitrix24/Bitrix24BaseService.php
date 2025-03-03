<?php

namespace App\Services\Bitrix24;

use GuzzleHttp\Client;

abstract class Bitrix24BaseService
{
    protected $client;
    protected $webhookUrl;
    protected $cacheTimeout = 3600;

    public function __construct()
    {
        $this->client = new Client();
        $this->webhookUrl = env('BITRIX24_WEBHOOK_URL');
    }

    protected function logError($message, $context = [])
    {
        \Log::error($message, array_merge($context, [
            'timestamp' => '2025-02-22 12:01:04',
            'user' => 'ibrokxim'
        ]));
    }
}
