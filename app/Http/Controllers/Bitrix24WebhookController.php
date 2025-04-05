<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TelegramService;
use App\Services\Bitrix24\DealService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Bitrix24WebhookController extends Controller
{
    protected $telegramService;
    protected $dealService;

    public function __construct(TelegramService $telegramService, DealService $dealService)
    {
        $this->telegramService = $telegramService;
        $this->dealService = $dealService;
    }

    public function handleDealUpdate(Request $request)
    {
        try {
            // Логируем входящие данные
            Log::info('Получено уведомление об изменении сделки', [
                'event' => $request->input('event'),
                'event_handler_id' => $request->input('event_handler_id'),
                'data' => $request->input('data'),
                'auth' => $request->input('auth')
            ]);

            // Проверяем тип события
            if ($request->input('event') !== 'ONCRMDEALUPDATE') {
                Log::warning('Получено неверное событие', ['event' => $request->input('event')]);
                return response()->json(['message' => 'Неверный тип события'], 400);
            }

            // Получаем ID сделки
            $dealId = $request->input('data.FIELDS.ID');
            if (!$dealId) {
                Log::warning('ID сделки отсутствует в запросе');
                return response()->json(['message' => 'ID сделки не указан'], 400);
            }

            // Проверяем авторизацию
            $auth = $request->input('auth');
            if (!$this->validateAuth($auth)) {
                Log::warning('Ошибка авторизации вебхука', ['auth' => $auth]);
                return response()->json(['message' => 'Ошибка авторизации'], 401);
            }

            // Получаем детали сделки из Битрикс24
            $dealDetails = $this->dealService->getDeal($dealId);
            if (!$dealDetails) {
                Log::warning("Не удалось получить детали сделки {$dealId}");
                return response()->json(['message' => 'Сделка не найдена'], 404);
            }

            // Находим заказ по ID сделки
            $order = Order::where('bitrix_deal_id', $dealId)->first();
            if (!$order) {
                Log::warning("Заказ для сделки {$dealId} не найден");
                return response()->json(['message' => 'Заказ не найден'], 404);
            }

            // Получаем пользователя
            $user = $order->user;
            if (!$user || !$user->telegram_chat_id) {
                Log::warning("Пользователь не найден или не привязан Telegram чат для заказа {$order->id}");
                return response()->json(['message' => 'Пользователь не найден или не привязан Telegram'], 404);
            }

            // Обновляем статус заказа
            $newStageId = $dealDetails['STAGE_ID'] ?? null;
            if ($newStageId) {
                $order->status = $this->mapBitrixStageToStatus($newStageId);
                $order->save();

                // Отправляем уведомление пользователю
                $message = $this->getStatusMessage($order->status, $order->id);
                $this->telegramService->sendMessage($user->telegram_chat_id, $message);

                Log::info("Уведомление отправлено пользователю {$user->id} о заказе {$order->id}", [
                    'new_stage_id' => $newStageId,
                    'new_status' => $order->status
                ]);
            }

            return response()->json(['message' => 'Уведомление обработано успешно']);

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке вебхука: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['message' => 'Ошибка при обработке уведомления'], 500);
        }
    }

    protected function validateAuth($auth)
    {
        if (!$auth) {
            return false;
        }

        // Проверяем обязательные поля
        $requiredFields = ['scope', 'domain', 'server_endpoint', 'status', 
                          'client_endpoint', 'member_id', 'application_token'];
        
        foreach ($requiredFields as $field) {
            if (!isset($auth[$field])) {
                return false;
            }
        }

        // Проверяем scope
        if ($auth['scope'] !== 'crm') {
            return false;
        }

        // Здесь можно добавить дополнительные проверки
        // Например, проверку application_token

        return true;
    }

    protected function mapBitrixStageToStatus($stageId)
    {
        $statusMap = [
            'NEW' => 'new',
            'PREPARATION' => 'processing',
            'PREPAYMENT_INVOICE' => 'pending_payment',
            'EXECUTING' => 'processing',
            'FINAL_INVOICE' => 'completed',
            'WON' => 'completed',
            'LOSE' => 'cancelled',
        ];

        return $statusMap[$stageId] ?? 'unknown';
    }

    protected function getStatusMessage($status, $orderId)
    {
        $messages = [
            'new' => "🆕 Ваш заказ №{$orderId} принят в обработку",
            'processing' => "⚙️ Заказ №{$orderId} обрабатывается",
            'pending_payment' => "💳 Ожидается оплата заказа №{$orderId}",
            'completed' => "✅ Заказ №{$orderId} выполнен",
            'cancelled' => "❌ Заказ №{$orderId} отменен",
            'unknown' => "📝 Статус заказа №{$orderId} обновлен",
        ];

        return $messages[$status] ?? $messages['unknown'];
    }
}