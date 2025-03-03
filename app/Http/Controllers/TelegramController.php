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

            // Удаляем кнопки
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

            // Получаем пользователя
            $user = User::findOrFail($targetUserId);

            // Обновляем статус пользователя
            $user->update(['status' => 'approved']);

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

            $leadResponse = $this->bitrix24Service->createLead($contactData);

            Log::info('Ответ от Битрикс24:', ['response' => $leadResponse]);

            if ($leadResponse['status'] === 'error') {
                Log::error("Ошибка при создании лида в Битрикс24: " . $leadResponse['message']);
            }

            // Отправляем уведомление пользователю
            $this->telegramService->sendApprovalMessage($user);

            // Ответ на callback
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '✅ Пользователь одобрен!'
            );

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке одобрения:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Отправляем уведомление об ошибке
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Произошла ошибка при обработке запроса'
            );
        }
    }

    private function processRejectAction($data, $chatId, $messageId, $callbackQueryId)
    {
        try {
            $targetUserId = (int) str_replace('reject_user_', '', $data);

            // Удаляем кнопки
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId);

            // Обновляем статус пользователя
            $user = User::findOrFail($targetUserId);
            $user->update(['status' => 'rejected']);

            // Можно добавить запись в Битрикс24 об отклонённой заявке, если нужно
            if ($user->bitrix24_contact_id) {
                $this->bitrix24Service->updateLeadStatus($user->bitrix24_contact_id, 'REJECTED');
            }

            // Отправляем уведомление пользователю
            $this->telegramService->sendRejectionMessage($user);

            // Ответ на callback
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Пользователь отклонен!'
            );

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке отклонения:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                '❌ Произошла ошибка при обработке запроса'
            );
        }
    }

    private function isAdmin($telegramUserId)
    {
        $adminIds = [289116384];
        return in_array($telegramUserId, $adminIds);
    }
}
