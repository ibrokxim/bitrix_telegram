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
     * Обрабатывает входящие события от Bitrix24 через исходящий вебхук
     */
    public function handleEvent(Request $request)
    {
        // Логируем входящие данные
        Log::info('Получены данные от Bitrix24 webhook:', $request->all());

        try {
            // Проверяем, что это событие обновления сделки
            if ($request->input('event') === 'ONCRMDEALUPDATE') {
                $fields = $request->input('data.FIELDS', []);
                
                // Проверяем изменение статуса сделки
                if (isset($fields['STAGE_ID'])) {
                    $dealId = $fields['ID'] ?? null;
                    $newStageId = $fields['STAGE_ID'];

                    Log::info('Обновление статуса сделки:', [
                        'deal_id' => $dealId,
                        'new_stage' => $newStageId
                    ]);

                    // Находим заказ по ID сделки в Bitrix24
                    $order = Order::where('bitrix24_deal_id', $dealId)->first();

                    if ($order) {
                        // Маппинг статусов из Bitrix24 в статусы вашей системы
                        $statusMapping = [
                            'C1:NEW' => 'new',                    // Новая сделка
                            'C1:PREPARATION' => 'processed',       // В работе
                            'C1:PREPAYMENT_INVOICE' => 'confirmed', // Счет на предоплату
                            'C1:EXECUTING' => 'shipped',           // В процессе доставки
                            'C1:FINAL_INVOICE' => 'delivered',     // Доставлено
                            'C1:WON' => 'completed',              // Сделка завершена
                            'C1:LOSE' => 'canceled'               // Сделка отменена
                        ];

                        $newStatus = $statusMapping[$newStageId] ?? 'unknown';
                        $oldStatus = $order->status;

                        if ($newStatus !== $oldStatus) {
                            // Обновляем статус заказа
                            $order->status = $newStatus;
                            $order->save();

                            // Отправляем уведомление через Telegram
                            $this->telegramService->sendOrderStatusChangedNotification(
                                $order,
                                $oldStatus,
                                $newStatus
                            );

                            Log::info('Статус заказа успешно обновлен', [
                                'order_id' => $order->id,
                                'old_status' => $oldStatus,
                                'new_status' => $newStatus,
                                'bitrix24_deal_id' => $dealId
                            ]);
                        }
                    } else {
                        Log::warning('Заказ не найден для сделки Bitrix24', [
                            'bitrix24_deal_id' => $dealId
                        ]);
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Событие успешно обработано'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке webhook от Bitrix24: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка при обработке события: ' . $e->getMessage()
            ], 500);
        }
    }
} 