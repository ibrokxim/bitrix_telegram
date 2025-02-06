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
        $message = "🇷🇺 Ваш запрос одобрен! ✅
        Нажмите на кнопку ниже, чтобы перейти в маркетплейс 👇\n\n
        🇺🇿 So’rovingiz qabul qilindi! ✅
        Marketplace'ga o’tish uchun quyidagi tugmani bosing 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                            'text' => 'Открыть/Ochish',
                            'url' => "https://t.me/kadyrov_urologbot/market"
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

        $message = "🇷🇺 ❌ К сожалению, ваш запрос был отклонен.\n\nСвяжитесь с администратором для получения дополнительной информации.
            🇺🇿❌ Afsuski, so’rovingiz rad etildi.\n\nQo’shimcha ma’lumot uchun administrator bilan bog’laning.";

        $this->bot->sendMessage([
            'chat_id' => $chatId,
            'text' => $message
        ]);
    }


    public function handleStartCommand($chatId)
    {
        Log::info('Отправка приветственного сообщения для chat_id:', ['chat_id' => $chatId]);

        // Отправляем приветственное сообщение
        $message = "🇷🇺 👋 Привет! Добро пожаловать в наш бот.\n\nДля продолжения работы, пожалуйста, зарегистрируйтесь в мини-приложении 🇺🇿 👋 Salom! Bizning  botimizga xush kelibsiz.\n\nDavom etish uchun, iltimos, quyidagi ilovada ro’yxatdan o’ting.";

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

        // Сохраняем chat_id в базе данных (если пользователь уже существует)
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
    public function answerCallbackQuery($callbackQueryId, $text)
    {
        try {
            $this->bot->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => $text
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка ответа на callback: ' . $e->getMessage());
        }
    }

    public function editMessageReplyMarkup($chatId, $messageId)
    {
        try {
            $this->bot->editMessageReplyMarkup([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []])
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка редактирования сообщения: ' . $e->getMessage());
        }
    }

}
