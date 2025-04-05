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
            // Получаем и проверяем данные
            $data = $request->all();
            Log::info('Webhook data received', ['data' => $data]);

            if (!isset($data['data']['FIELDS']['ID'])) {
                Log::error('Deal ID not found in webhook data', ['data' => $data]);
                return response()->json(['error' => 'Deal ID not provided'], 400);
            }

            $dealId = $data['data']['FIELDS']['ID'];
            Log::info('Processing deal', ['deal_id' => $dealId]);
            
            // Находим заказ
            $order = Order::where('bitrix_deal_id', $dealId)->first();
            if (!$order) {
                Log::error('Order not found for deal', ['deal_id' => $dealId]);
                return response()->json(['error' => 'Order not found'], 404);
            }
            Log::info('Found order', ['order_id' => $order->id, 'deal_id' => $dealId]);

            // Проверяем пользователя
            $user = $order->user;
            if (!$user) {
                Log::error('User not found for order', ['order_id' => $order->id]);
                return response()->json(['error' => 'User not found'], 404);
            }
            if (!$user->telegram_chat_id) {
                Log::error('Telegram chat ID not found for user', ['user_id' => $user->id]);
                return response()->json(['error' => 'Telegram not connected'], 404);
            }
            Log::info('Found user', ['user_id' => $user->id, 'telegram_chat_id' => $user->telegram_chat_id]);

            // Получаем новый статус из Bitrix24
            $newStageId = $data['data']['FIELDS']['STAGE_ID'] ?? null;
            Log::info('Stage ID from webhook', ['stage_id' => $newStageId]);
            
            if ($newStageId) {
                $oldStatus = $order->status;
                $newStatus = $this->mapBitrixStageToStatus($newStageId);
                Log::info('Status mapping', [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'bitrix_stage' => $newStageId
                ]);

                $order->status = $newStatus;
                $order->save();

                // Отправляем уведомление пользователю
                $message = $this->getStatusMessage($newStatus, $order->id, $newStageId);
                Log::info('Sending message to user', [
                    'chat_id' => $user->telegram_chat_id,
                    'message' => $message
                ]);

                $sent = $this->telegramService->sendMessage($user->telegram_chat_id, $message);
                if (!$sent) {
                    Log::error('Failed to send Telegram message', [
                        'chat_id' => $user->telegram_chat_id,
                        'message' => $message
                    ]);
                }
            } else {
                Log::info('No stage ID in webhook data, skipping update');
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Internal error'], 500);
        }
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

    protected function getStatusMessage($status, $orderId, $bitrixStageId)
    {
        $stageNames = [
            'NEW' => 'Новый',
            'PREPARATION' => 'В обработке',
            'PREPAYMENT_INVOICE' => 'Ожидает оплату',
            'EXECUTING' => 'В работе',
            'FINAL_INVOICE' => 'Готов к выдаче',
            'WON' => 'Выполнен',
            'LOSE' => 'Отменён'
        ];

        $stageName = $stageNames[$bitrixStageId] ?? $bitrixStageId;
        
        $messages = [
            'new' => "🆕 Ваш заказ #{$orderId} принят в обработку\nСтатус: {$stageName}",
            'processing' => "⚙️ Заказ #{$orderId} обрабатывается\nСтатус: {$stageName}",
            'pending_payment' => "💳 Ожидается оплата заказа #{$orderId}\nСтатус: {$stageName}",
            'completed' => "✅ Заказ #{$orderId} выполнен\nСтатус: {$stageName}",
            'cancelled' => "❌ Заказ #{$orderId} отменен\nСтатус: {$stageName}",
            'unknown' => "ℹ️ Статус заказа #{$orderId} обновлен\nНовый статус: {$stageName}",
        ];

        return $messages[$status] ?? $messages['unknown'];
    }
}