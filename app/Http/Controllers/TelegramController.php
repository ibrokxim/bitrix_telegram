<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\TelegramService;
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
        try {
            Log::info('Webhook received', ['data' => $request->all()]);

            $update = $request->all();

            // Обработка callback query (нажатие на кнопки)
            if (isset($update['callback_query'])) {
                return $this->handleCallbackQuery($update['callback_query']);
            }

            // Обработка обычных сообщений
            if (isset($update['message'])) {
                $message = $update['message'];
                $chatId = $message['chat']['id'];
                $text = $message['text'] ?? '';

                if ($text === '/start') {
                    $this->telegramService->handleStartCommand($chatId);
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Webhook error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    protected function handleCallbackQuery($callbackQuery)
    {
        try {
            $data = $callbackQuery['data'];
            $chatId = $callbackQuery['from']['id'];

            // Разбираем данные callback_query
            if (strpos($data, 'approve_user_') === 0) {
                $userId = substr($data, strlen('approve_user_'));
                return $this->approveUser($userId, $chatId);
            }

            if (strpos($data, 'reject_user_') === 0) {
                $userId = substr($data, strlen('reject_user_'));
                return $this->rejectUser($userId, $chatId);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Callback query error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    protected function approveUser($userId, $chatId)
    {
        try {
            $user = User::findOrFail($userId);
            $user->status = 'approved';
            $user->save();

            // Отправляем сообщение администратору
            $this->telegramService->sendMessage($chatId, "✅ Пользователь {$user->first_name} {$user->second_name} одобрен");

            // Отправляем сообщение пользователю
            $this->telegramService->sendMessage($user->telegram_chat_id,
                "✅ Ваша заявка одобрена!\nТеперь вы можете использовать мини-приложение: https://lms.tuit.uz"
            );

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Error approving user:', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    protected function rejectUser($userId, $chatId)
    {
        try {
            $user = User::findOrFail($userId);
            $user->status = 'rejected';
            $user->save();

            // Отправляем сообщение администратору
            $this->telegramService->sendMessage($chatId, "❌ Пользователь {$user->first_name} {$user->second_name} отклонен");

            // Отправляем сообщение пользователю
            $this->telegramService->sendMessage($user->telegram_chat_id,
                "❌ К сожалению, ваша заявка была отклонена."
            );

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Error rejecting user:', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }
}
