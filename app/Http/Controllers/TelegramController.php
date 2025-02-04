<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function handleWebhook(Request $request)
    {
        Log::info('Вебхук получен:', $request->all());
        $update = $request->all();

        // Обработка команды /start
        if (isset($update['message']['text']) && $update['message']['text'] === '/start') {
            $this->handleStartCommand($update['message']['chat']['id']);
        }

        // Обработка callback-запросов
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }

        return response()->json(['status' => 'success']);
    }

    private function handleStartCommand($chatId)
    {
        Log::info('Обработка команды /start для chat_id: ' . $chatId);
        $this->telegramService->handleStartCommand($chatId);
    }

    private function handleCallbackQuery($callbackQuery)
    {
        try {
            $data = $callbackQuery['data'];
            $messageId = $callbackQuery['message']['message_id'];
            $chatId = $callbackQuery['message']['chat']['id'];
            $userId = $callbackQuery['from']['id'];

            // Проверка прав администратора
            if (!$this->isAdmin($userId)) {
                $this->telegramService->answerCallbackQuery(
                    $callbackQuery['id'],
                    '⛔ У вас нет прав для этого действия!'
                );
                return;
            }

            // Обработка действий
            if (str_starts_with($data, 'approve_user_')) {
                $this->processApproveAction($data, $chatId, $messageId, $callbackQuery['id']);
            } elseif (str_starts_with($data, 'reject_user_')) {
                $this->processRejectAction($data, $chatId, $messageId, $callbackQuery['id']);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка обработки callback: ' . $e->getMessage());
        }
    }

    private function processApproveAction($data, $chatId, $messageId, $callbackQueryId)
    {
        $targetUserId = (int) str_replace('approve_user_', '', $data);

        // Удаляем кнопки
        $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

        // Обновляем статус пользователя
        $user = User::findOrFail($targetUserId);
        $user->update(['status' => 'approved']);

        // Отправляем уведомление пользователю
        $this->telegramService->sendApprovalMessage($user);

        // Ответ на callback
        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            '✅ Пользователь одобрен!'
        );
    }

    private function processRejectAction($data, $chatId, $messageId, $callbackQueryId)
    {
        $targetUserId = (int) str_replace('reject_user_', '', $data);

        // Удаляем кнопки
        $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

        // Обновляем статус пользователя
        $user = User::findOrFail($targetUserId);
        $user->update(['status' => 'rejected']);

        // Отправляем уведомление пользователю
        $this->telegramService->sendRejectionMessage($user);

        // Ответ на callback
        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            '❌ Пользователь отклонен!'
        );
    }

    private function isAdmin($telegramUserId)
    {
        $adminIds = [289116384];
        return in_array($telegramUserId, $adminIds);
    }
}
