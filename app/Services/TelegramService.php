<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramBot;
class TelegramService
{
    protected $bot;
    protected $adminChatId;

    public function __construct()
    {
        $this->bot = new TelegramBot(env('TELEGRAM_BOT_TOKEN'));
        $this->adminChatId = env('TELEGRAM_ADMIN_GROUP_ID');
    }

    public function sendMessageToAdminGroup($message, $keyboard)
    {
        $this->bot->sendMessage([
            'chat_id' => $this->adminChatId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function sendApprovalMessage(User $user)
    {
        if (!$user->telegram_chat_id) {
            \Log::warning("No Telegram chat ID for user {$user->id}");
            return;
        }
        $message = "✅ Ваш запрос одобрен!\n\n" .
            "Нажмите на кнопку ниже, чтобы перейти в мини-приложение:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Открыть мини-приложение',
                        'web_app' => [
                            'url' => "https://lms.tuit.uz"
                        ]
                    ]
                ]
            ]
        ];

        $this->bot->sendMessage([
            'chat_id' => $user->telegram_chat_id,
            'text' => $message,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function sendRejectionMessage(User $user)
    {
        $chatId = $user->telegram_chat_id;

        $message = "❌ К сожалению, ваш запрос был отклонен.\n\n" .
            "Свяжитесь с администратором для получения дополнительной информации.";

        $this->bot->sendMessage([
            'chat_id' => $chatId,
            'text' => $message
        ]);
    }


    public function handleStartCommand($chatId)
    {
        Log::info('Отправка приветственного сообщения для chat_id:', ['chat_id' => $chatId]);

        // Отправляем приветственное сообщение
        $message = "👋 Привет! Добро пожаловать в наш сервис.\n\n" .
            "Для продолжения работы, пожалуйста, зарегистрируйтесь в мини-приложении.";

        try {
            $this->bot->sendMessage([
                'chat_id' => $chatId,
                'text' => $message
            ]);
            Log::info('Приветственное сообщение отправлено для chat_id:', ['chat_id' => $chatId]);
        } catch (\Exception $e) {
            Log::error('Ошибка при отправке сообщения:', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
        }

        $user = User::where('telegram_chat_id', $chatId)->first();
        if (!$user) {
            try {
                User::create([
                    'telegram_chat_id' => $chatId,
                    'status' => 'pending'
                ]);
                Log::info('Пользователь создан для chat_id:', ['chat_id' => $chatId]);
            } catch (\Exception $e) {
                Log::error('Ошибка при создании пользователя:', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
