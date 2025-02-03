<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected $token;
    protected $apiUrl;
    protected $adminGroupId; // ID группы администраторов

    public function __construct()
    {
        $this->token = '7836147847:AAGNAGch5VPxQOERmtDif2NeHr5KrjWRO-c';
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}/";
        $this->adminGroupId = env('TELEGRAM_ADMIN_GROUP_ID'); // Замените на реальный ID группы
    }

    public function handleStartCommand($chatId)
    {
        $message = "Добро пожаловать! 👋\n\n";
        $message .= "Это бот компании KadyrovMedical.\n";
        $message .= "Чем могу помочь?";

        return $this->sendMessage($chatId, $message);
    }

    public function sendMessage($chatId, $text, $keyboard = null)
    {
        try {
            $data = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ];

            if ($keyboard) {
                $data['reply_markup'] = json_encode($keyboard);
            }

            $response = Http::post($this->apiUrl . 'sendMessage', $data);

            if (!$response->successful()) {
                Log::error('Telegram API Error:', [
                    'response' => $response->json(),
                    'chat_id' => $chatId
                ]);
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Error sending message:', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);
            return false;
        }
    }

    public function sendMessageToAdminGroup($message, $keyboard = null)
    {
        return $this->sendMessage($this->adminGroupId, $message, $keyboard);
    }

    public function answerCallbackQuery($callbackQueryId, $text = null)
    {
        try {
            $data = ['callback_query_id' => $callbackQueryId];
            if ($text) {
                $data['text'] = $text;
            }

            return Http::post($this->apiUrl . 'answerCallbackQuery', $data);
        } catch (\Exception $e) {
            Log::error('Error answering callback query:', [
                'error' => $e->getMessage(),
                'callback_query_id' => $callbackQueryId
            ]);
            return false;
        }
    }
}
