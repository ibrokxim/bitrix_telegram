<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BitrixWebhookController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Обрабатывает webhook-запрос от Битрикса при изменении сделки
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleDealUpdate(Request $request)
    {
        try {
            Log::info('Получен webhook от Битрикса (сделка):', $request->all());

            // Проверяем, что получен правильный запрос с ID сделки и новым статусом
            if (!$request->has('data') || !isset($request->data['FIELDS'])) {
                return response()->json(['status' => 'error', 'message' => 'Некорректные данные запроса'], 400);
            }

            $dealId = $request->data['FIELDS']['ID'] ?? null;
            $newStageId = $request->data['FIELDS']['STAGE_ID'] ?? null;

            if (!$dealId || !$newStageId) {
                return response()->json(['status' => 'error', 'message' => 'Отсутствуют обязательные поля'], 400);
            }

            // Находим заказ по Bitrix deal ID
            $order = Order::where('bitrix_deal_id', $dealId)->first();
            if (!$order) {
                return response()->json(['status' => 'error', 'message' => 'Заказ не найден'], 404);
            }

            // Сохраняем старый статус для логов и уведомления
            $oldStatus = $order->status;

            // Маппинг статусов Битрикса на статусы заказа в нашей системе
            $statusMapping = [
                'NEW' => 'new',
                'PREPARATION' => 'processed',
                'PREPAYMENT_INVOICE' => 'confirmed',
                'EXECUTING' => 'shipped',
                'FINAL_INVOICE' => 'delivered',
                'WON' => 'completed',
                'LOSE' => 'canceled',
            ];

            // Определяем новый статус заказа
            $newStatus = $statusMapping[$newStageId] ?? 'unknown';
            
            // Если статус не изменился, то не обновляем
            if ($newStatus === $oldStatus) {
                return response()->json(['status' => 'success', 'message' => 'Статус не изменился']);
            }

            // Обновляем статус заказа
            $order->status = $newStatus;
            $order->save();

            // Отправляем уведомление пользователю в зависимости от нового статуса
            if ($newStatus === 'canceled') {
                $this->telegramService->sendOrderCanceledNotification($order);
            } else {
                $this->telegramService->sendOrderStatusChangedNotification($order, $oldStatus, $newStatus);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Статус заказа обновлен',
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка обработки webhook Битрикса: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка обработки webhook: ' . $e->getMessage()
            ], 500);
        }
    }
} 