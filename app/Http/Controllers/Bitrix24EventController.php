<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Bitrix24EventController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Обрабатывает входящие события от Bitrix24
     */
    public function handleEvent(Request $request)
    {
        $data = $request->all();
        Log::info('Получено событие от Bitrix24:', $data);

        if (isset($data['event']) && $data['event'] === 'ONCRMDEALUPDATE') {
            $fields = $data['data']['FIELDS'] ?? [];

            // Проверяем изменение статуса сделки
            if (isset($fields['STAGE_ID'])) {
                $dealId = $fields['ID'] ?? null;
                $newStageId = $fields['STAGE_ID'];

                // Находим заказ по ID сделки в Bitrix24
                $order = Order::where('bitrix24_deal_id', $dealId)->first();

                if ($order) {
                    // Маппинг статусов Bitrix24 в статусы вашей системы
                    $statusMapping = [
                        'NEW' => 'new',
                        'PREPARATION' => 'processed',
                        'PREPAYMENT_INVOICE' => 'confirmed',
                        'EXECUTING' => 'shipped',
                        'FINAL_INVOICE' => 'delivered',
                        'WON' => 'completed',
                        'LOSE' => 'canceled'
                    ];

                    $newStatus = $statusMapping[$newStageId] ?? 'unknown';
                    $oldStatus = $order->status;

                    // Обновляем статус заказа
                    $order->status = $newStatus;
                    $order->save();

                    // Отправляем уведомление через Telegram
                    $this->telegramService->sendOrderStatusChangedNotification(
                        $order,
                        $oldStatus,
                        $newStatus
                    );

                    Log::info('Статус заказа обновлен', [
                        'order_id' => $order->id,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'bitrix24_deal_id' => $dealId
                    ]);
                } else {
                    Log::warning('Заказ не найден для сделки Bitrix24', [
                        'bitrix24_deal_id' => $dealId
                    ]);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
} 