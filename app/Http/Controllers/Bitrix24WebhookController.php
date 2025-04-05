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
            $data = $request->all();
            Log::info('Webhook data received', ['data' => $data]);

            if (!isset($data['data']['FIELDS']['ID'])) {
                Log::error('Deal ID not found in webhook data', ['data' => $data]);
                return response()->json(['error' => 'Deal ID not provided'], 400);
            }

            $dealId = $data['data']['FIELDS']['ID'];
            Log::info('Processing deal', ['deal_id' => $dealId]);
            
            // Получаем детали сделки через API Bitrix24
            $dealDetails = $this->dealService->getDeal($dealId);
            if (!$dealDetails) {
                Log::error('Failed to get deal details from Bitrix24', ['deal_id' => $dealId]);
                return response()->json(['error' => 'Failed to get deal details'], 404);
            }

            $newStageId = $dealDetails['STAGE_ID'] ?? null;
            Log::info('Stage ID from Bitrix24', ['stage_id' => $newStageId, 'deal_details' => $dealDetails]);

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
                Log::info('No stage ID in deal details, skipping update');
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'deal_id' => $dealId ?? null
            ]);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    protected function mapBitrixStageToStatus($stageId)
    {
        // Убираем префикс C5: если он есть
        $stageId = str_replace('C5:', '', $stageId);

        $statusMap = [
            'NEW' => 'new',                    // Заявка принята
            'PREPARATION' => 'processing',      // Квалификация проведена
            'PREPAYMENT_INVOICE' => 'processing', // Встреча назначена
            'EXECUTING' => 'processing',        // Встреча проведена
            'FINAL_INVOICE' => 'processing',    // Дожим на договор
            '1' => 'processing',               // Договор составлен
            '2' => 'pending_payment',          // Оплата получена
            'WON' => 'completed',              // Сделка успешна
            'LOSE' => 'cancelled',             // Сделка провалена
            'APOLOGY' => 'cancelled',          // Анализ причины провала
        ];

        return $statusMap[$stageId] ?? 'unknown';
    }

    protected function getStatusMessage($status, $orderId, $bitrixStageId)
    {
        // Убираем префикс C5: если он есть
        $bitrixStageId = str_replace('C5:', '', $bitrixStageId);

        $stageNames = [
            'NEW' => 'Заявка принята',
            'PREPARATION' => 'Квалификация проведена',
            'PREPAYMENT_INVOICE' => 'Встреча назначена',
            'EXECUTING' => 'Встреча проведена',
            'FINAL_INVOICE' => 'Дожим на договор',
            '1' => 'Договор составлен',
            '2' => 'Оплата получена',
            'WON' => 'Сделка успешна',
            'LOSE' => 'Сделка отменена',
            'APOLOGY' => 'Анализ причины отмены'
        ];

        $stageName = $stageNames[$bitrixStageId] ?? 'Статус неизвестен';
        
        $messages = [
            'new' => "🆕 Ваш заказ #{$orderId}\nСтатус: {$stageName}",
            'processing' => "⚙️ Заказ #{$orderId}\nСтатус: {$stageName}",
            'pending_payment' => "💳 Заказ #{$orderId}\nСтатус: {$stageName}",
            'completed' => "✅ Заказ #{$orderId}\nСтатус: {$stageName}",
            'cancelled' => "❌ Заказ #{$orderId}\nСтатус: {$stageName}",
            'unknown' => "ℹ️ Заказ #{$orderId}\nСтатус: {$stageName}",
        ];

        return $messages[$status] ?? $messages['unknown'];
    }
}