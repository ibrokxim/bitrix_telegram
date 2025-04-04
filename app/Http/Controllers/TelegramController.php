<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use App\Services\Bitrix24\Bitrix24Service;

class TelegramController extends Controller
{
    protected $telegramService;
    protected $bitrix24Service;

    public function __construct(TelegramService $telegramService, Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
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
        try {
            $targetUserId = (int) str_replace('approve_user_', '', $data);

            // Получаем пользователя
            $user = User::findOrFail($targetUserId);

            // Удаляем кнопки
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

            // Обновляем статус пользователя
            $user->update(['status' => 'approved']);

            Log::info('Обработка одобрения пользователя', [
                'user_id' => $user->id,
                'admin_chat_id' => $chatId
            ]);

            // Подготавливаем данные для создания контакта в Битрикс24
            $contactData = [
                'NAME' => $user->first_name,
                'SECOND_NAME' => $user->second_name,
                'LAST_NAME' => $user->last_name,
                'PHONE' => [['VALUE' => $user->phone, 'VALUE_TYPE' => 'WORK']],
                'SOURCE_ID' => 'WEB',
                'ASSIGNED_BY_ID' => 1,
                'TYPE_ID' => 'CLIENT',
                'OPENED' => 'Y',
                'COMMENTS' => 'Клиент зарегистрирован через мини-приложение'
            ];

            if ($user->is_legal_entity) {
                $contactData['UF_CRM_1708963461'] = 'Да'; // ID поля для юр. лица
                if ($user->inn) {
                    $contactData['UF_CRM_1708963492'] = $user->inn;
                }
                if ($user->company_name) {
                    $contactData['COMPANY_TITLE'] = $user->company_name;
                }
                if ($user->position) {
                    $contactData['POST'] = $user->position;
                }
            }

            Log::info('Отправка данных в Битрикс24:', ['contactData' => $contactData]);

            // Создаем контакт в Битрикс24
            $contactResponse = $this->bitrix24Service->createContact($contactData);

            Log::info('Ответ от Битрикс24:', ['response' => $contactResponse]);

            if ($contactResponse['status'] === 'error') {
                throw new \Exception("Ошибка при создании контакта в Битрикс24: " . $contactResponse['message']);
            }

            // Обновляем ID контакта в базе данных
            $user->update([
                'bitrix_contact_id' => $contactResponse['contact_id']
            ]);

            // Отправляем уведомление пользователю
            try {
                $this->telegramService->sendApprovalMessage($user);
                Log::info('Уведомление об одобрении отправлено', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('Ошибка при отправке уведомления об одобрении: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            // Ответ на callback
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '✅ Пользователь одобрен!'
            );

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке одобрения:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $targetUserId ?? null
            ]);

            // Отправляем уведомление об ошибке
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Произошла ошибка: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    private function processRejectAction($data, $chatId, $messageId, $callbackQueryId)
    {
        try {
            $targetUserId = (int) str_replace('reject_user_', '', $data);

            // Получаем пользователя
            $user = User::findOrFail($targetUserId);

            // Удаляем кнопки
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

            Log::info('Обработка отклонения пользователя', [
                'user_id' => $user->id,
                'admin_chat_id' => $chatId
            ]);

            // Обновляем статус пользователя
            $user->update(['status' => 'rejected']);

            // Отправляем уведомление пользователю
            try {
                $this->telegramService->sendRejectionMessage($user);
                Log::info('Уведомление об отклонении отправлено', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('Ошибка при отправке уведомления об отклонении: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            // Ответ на callback
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Пользователь отклонен!'
            );

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке отклонения:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $targetUserId ?? null
            ]);

            // Отправляем уведомление об ошибке
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Произошла ошибка: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    private function isAdmin($telegramUserId)
    {
        $adminIds = [289116384];
        return in_array($telegramUserId, $adminIds);
    }
}
