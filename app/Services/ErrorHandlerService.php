<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ErrorHandlerService
{
    private $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function handleError(\Throwable $exception, array $context = [])
    {
        // Получаем текущего пользователя
        $currentUser = Auth::user();
        $userName = $currentUser ? $currentUser->name : 'System';

        // Форматируем сообщение об ошибке
        $errorMessage = $this->formatErrorMessage($exception, $context, $userName);

        // Логируем ошибку
        Log::error($errorMessage, [
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'user' => $userName,
            'trace' => $exception->getTraceAsString()
        ]);

        // Отправляем сообщение в Telegram
        $this->sendToTelegram($errorMessage);
    }

    private function formatErrorMessage(\Throwable $exception, array $context = [], string $userName = 'System'): string
    {
        $currentTime = now()->format('Y-m-d H:i:s');

        $message = "🚨 *Ошибка в боте*\n\n";
        $message .= "📅 Время: {$currentTime}\n";
        $message .= "👤 Пользователь: {$userName}\n\n";
        $message .= "❌ Тип ошибки: " . get_class($exception) . "\n";
        $message .= "📝 Сообщение: " . $exception->getMessage() . "\n";
        $message .= "📍 Файл: " . $exception->getFile() . "\n";
        $message .= "📍 Строка: " . $exception->getLine() . "\n\n";

        if (!empty($context)) {
            $message .= "📋 Контекст:\n";
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $message .= "- {$key}: {$value}\n";
            }
        }

        return $message;
    }

    private function sendToTelegram(string $message)
    {
        try {
            $this->telegramService->sendMessageToAdminGroup($message, [], true);
        } catch (\Exception $e) {
            $currentUser = Auth::user();
            $userName = $currentUser ? $currentUser->name : 'System';

            Log::error('Failed to send error message to Telegram', [
                'error' => $e->getMessage(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'user' => $userName
            ]);
        }
    }
}
