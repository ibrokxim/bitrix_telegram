<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramBot;
use Illuminate\Support\Facades\Http;

class TelegramService
{
    protected $bot;
    protected $adminChatId;
    protected $token;

    public function __construct()
    {
        $this->bot = new TelegramBot(env('TELEGRAM_BOT_TOKEN'));
        $this->adminChatId = env('TELEGRAM_ADMIN_GROUP_ID');
        $this->token = config('services.telegram.bot_token');
    }

    public function sendMessageToAdminGroup($message, $keyboard = null, $parseMode = false)
    {
        try {
            $params = [
                'chat_id' => $this->adminChatId,
                'text' => $message
            ];

            if ($keyboard !== null) {
                $params['reply_markup'] = json_encode($keyboard);
            }

            if ($parseMode) {
                $params['parse_mode'] = 'Markdown';
            }

            $result = $this->bot->sendMessage($params);
            
            Log::info('Message sent to admin group', [
                'chat_id' => $this->adminChatId,
                'message' => $message
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error sending message to admin group: ' . $e->getMessage(), [
                'chat_id' => $this->adminChatId,
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendApprovalMessage(User $user)
    {
        try {
            if (!$user->telegram_chat_id) {
                Log::warning("No Telegram chat ID for user {$user->id}");
                return;
            }
            
            $message = "🇷🇺 Ваш запрос одобрен! ✅
Нажмите на кнопку ниже, чтобы перейти в маркетплейс 👇\n🇺🇿 So'rovingiz qabul qilindi! ✅
Marketplace'ga o'tish uchun quyidagi tugmani bosing 👇";

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

            $result = $this->bot->sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'reply_markup' => json_encode($keyboard)
            ]);

            Log::info('Approval message sent successfully', [
                'user_id' => $user->id,
                'telegram_chat_id' => $user->telegram_chat_id
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error sending approval message: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'telegram_chat_id' => $user->telegram_chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function sendRejectionMessage(User $user)
    {
        try {
            if (!$user->telegram_chat_id) {
                Log::warning("No Telegram chat ID for user {$user->id}");
                return;
            }

            $message = "🇷🇺 ❌ К сожалению, ваш запрос был отклонен.\n\nСвяжитесь с администратором для получения дополнительной информации.
            🇺🇿❌ Afsuski, so'rovingiz rad etildi.\n\nQo'shimcha ma'lumot uchun administrator bilan bog'laning.";

            $result = $this->bot->sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message
            ]);

            Log::info('Rejection message sent successfully', [
                'user_id' => $user->id,
                'telegram_chat_id' => $user->telegram_chat_id
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error sending rejection message: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'telegram_chat_id' => $user->telegram_chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }


    public function handleStartCommand($chatId)
    {
        Log::info('Отправка приветственного сообщения для chat_id:', ['chat_id' => $chatId]);

        // Отправляем приветственное сообщение
        $message = "
        🇷🇺 Добро пожаловать на наш маркетплейс!👋

Чтобы получить доступ ко всем товарам, нажмите на кнопку ниже 👇 и пройдите регистрацию.

🇺🇿 Marketplace'imizga xush kelibsiz!👋

Barcha mahsulotlarni ko'rish uchun quyidagi tugmani bosing 👇 va ro'yxatdan o'ting.
        ";
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
        try {
            $this->bot->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'reply_markup' => json_encode($keyboard)
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


    private function sendTelegramRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot{$this->bot}/{$method}";
        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->post($url, [
                'json' => $data
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $currentUser = Auth::user();
            $userName = $currentUser ? $currentUser->name : 'System';

            \Log::error('Telegram API Error: ' . $e->getMessage(), [
                'method' => $method,
                'data' => $data,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'user' => $userName
            ]);
            throw $e;
        }
    }

    /**
     * Отправляет уведомление пользователю о создании заказа
     * 
     * @param \App\Models\Order $order Заказ
     * @return void
     */
    public function sendOrderCreatedNotification($order)
    {
        $user = $order->user;
        if (!$user || !$user->telegram_chat_id) {
            \Log::warning("Не удалось отправить уведомление о создании заказа: пользователь не найден или отсутствует telegram_chat_id", ['order_id' => $order->id]);
            return;
        }
        
        // Форматируем информацию о продуктах
        $productsData = json_decode($order->products, true);
        $productsText = "";
        
        foreach ($productsData as $product) {
            $productName = $product['name'] ?? 'Неизвестный продукт';
            $quantity = $product['quantity'] ?? 1;
            $price = $product['price'] ?? 0;
            $subtotal = $quantity * $price;
            
            $productsText .= "- {$productName} x {$quantity} = " . number_format($subtotal, 0, '.', ' ') . " UZS\n";
        }
        
        $message = "🛒 *Ваш заказ #{$order->id} успешно создан!*\n\n";
        $message .= "*Информация о заказе:*\n";
        $message .= $productsText . "\n";
        $message .= "*Общая сумма:* " . number_format($order->total_amount, 0, '.', ' ') . " UZS\n\n";
        $message .= "Ваш заказ принят и находится в обработке. Мы свяжемся с вами в ближайшее время.";
        
        try {
            $this->bot->sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
            \Log::info("Отправлено уведомление о создании заказа", ['order_id' => $order->id, 'user_id' => $user->id]);
        } catch (\Exception $e) {
            \Log::error("Ошибка при отправке уведомления о создании заказа: " . $e->getMessage(), [
                'order_id' => $order->id, 
                'user_id' => $user->id
            ]);
        }
    }
    
    /**
     * Отправляет уведомление пользователю об изменении статуса заказа
     * 
     * @param \App\Models\Order $order Заказ
     * @param string $oldStatus Старый статус
     * @param string $newStatus Новый статус
     * @return void
     */
    public function sendOrderStatusChangedNotification($order, $oldStatus, $newStatus)
    {
        $user = $order->user;
        if (!$user || !$user->telegram_chat_id) {
            \Log::warning("Не удалось отправить уведомление об изменении статуса заказа: пользователь не найден или отсутствует telegram_chat_id", ['order_id' => $order->id]);
            return;
        }
        
        // Перевод статусов на русский язык
        $statusTranslations = [
            'new' => 'Новый',
            'processed' => 'Обработан',
            'confirmed' => 'Подтвержден',
            'shipped' => 'Отправлен',
            'delivered' => 'Доставлен',
            'completed' => 'Завершен',
            'canceled' => 'Отменен',
            'rejected' => 'Отклонен'
        ];
        
        $newStatusText = $statusTranslations[$newStatus] ?? $newStatus;
        
        $message = "🔄 *Обновление статуса заказа #{$order->id}*\n\n";
        $message .= "Статус вашего заказа изменился на: *{$newStatusText}*\n\n";
        
        // Добавляем дополнительную информацию в зависимости от статуса
        if ($newStatus == 'confirmed') {
            $message .= "Ваш заказ подтвержден и готовится к отправке.";
        } elseif ($newStatus == 'shipped') {
            $message .= "Ваш заказ передан в доставку.";
        } elseif ($newStatus == 'delivered') {
            $message .= "Ваш заказ доставлен. Спасибо за покупку!";
        } elseif ($newStatus == 'completed') {
            $message .= "Ваш заказ успешно выполнен. Спасибо за покупку!";
        }
        
        try {
            $this->bot->sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
            \Log::info("Отправлено уведомление об изменении статуса заказа", [
                'order_id' => $order->id, 
                'user_id' => $user->id, 
                'old_status' => $oldStatus, 
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            \Log::error("Ошибка при отправке уведомления об изменении статуса заказа: " . $e->getMessage(), [
                'order_id' => $order->id, 
                'user_id' => $user->id
            ]);
        }
    }
    
    /**
     * Отправляет уведомление пользователю об отмене заказа
     * 
     * @param \App\Models\Order $order Заказ
     * @param string $reason Причина отмены (опционально)
     * @return void
     */
    public function sendOrderCanceledNotification($order, $reason = null)
    {
        $user = $order->user;
        if (!$user || !$user->telegram_chat_id) {
            \Log::warning("Не удалось отправить уведомление об отмене заказа: пользователь не найден или отсутствует telegram_chat_id", ['order_id' => $order->id]);
            return;
        }
        
        $message = "❌ *Заказ #{$order->id} отменен*\n\n";
        
        if ($reason) {
            $message .= "Причина: {$reason}\n\n";
        }
        
        $message .= "Если у вас есть вопросы, пожалуйста, свяжитесь с нашей службой поддержки.";
        
        try {
            $this->bot->sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
            \Log::info("Отправлено уведомление об отмене заказа", [
                'order_id' => $order->id, 
                'user_id' => $user->id, 
                'reason' => $reason
            ]);
        } catch (\Exception $e) {
            \Log::error("Ошибка при отправке уведомления об отмене заказа: " . $e->getMessage(), [
                'order_id' => $order->id, 
                'user_id' => $user->id
            ]);
        }
    }

    /**
     * Отправляет сообщение через Telegram
     * 
     * @param string $chatId ID чата пользователя
     * @param string $message Текст сообщения
     * @param array $options Дополнительные параметры (parse_mode, reply_markup и т.д.)
     * @return array|null
     */
    public function sendMessage($chatId, $message, $options = [])
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'text' => $message
            ], $options);

            $response = $this->bot->sendMessage($params);
            
            Log::info('Telegram notification sent', [
                'chat_id' => $chatId,
                'message' => $message,
                'options' => $options
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('Error sending Telegram message: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'message' => $message,
                'options' => $options,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Отправляет сообщение в админскую группу
     */
    public function sendMessageToAdmin($message)
    {
        $adminGroupId = config('services.telegram.admin_group_id');
        
        if (!$adminGroupId) {
            Log::error('ID админской группы Telegram не настроен');
            return false;
        }

        // Обработка текста перед отправкой
        $message = $this->sanitizeMessage($message);

        return $this->sendMessage($adminGroupId, $message);
    }

    /**
     * Очищает текст сообщения от проблемных символов
     */
    protected function sanitizeMessage($message)
    {
        // Преобразуем текст в UTF-8
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        
        // Удаляем непечатаемые символы
        $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);
        
        // Заменяем множественные пробелы одним
        $message = preg_replace('/\s+/', ' ', $message);
        
        return trim($message);
    }
}
