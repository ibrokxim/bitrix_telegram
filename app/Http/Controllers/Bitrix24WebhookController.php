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
                return response()->json(['error' => 'Deal ID not provided'], 400);
            }

            $dealId = $data['data']['FIELDS']['ID'];
            
            // Находим заказ
            $order = Order::where('bitrix_deal_id', $dealId)->first();
            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }

            // Проверяем пользователя
            $user = $order->user;
            if (!$user || !$user->telegram_chat_id) {
                return response()->json(['error' => 'User not found or Telegram not connected'], 404);
            }

            // Получаем новый статус из Bitrix24
            $newStageId = $data['data']['FIELDS']['STAGE_ID'] ?? null;
            if ($newStageId) {
                $oldStatus = $order->status;
                $order->status = $this->mapBitrixStageToStatus($newStageId);
                $order->save();

                // Отправляем уведомление пользователю
                $message = $this->getStatusMessage($order->status, $order->id, $newStageId);
                $this->telegramService->sendMessage($user->telegram_chat_id, $message);
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