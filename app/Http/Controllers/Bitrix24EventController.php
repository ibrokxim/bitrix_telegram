<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Bitrix24EventController extends Controller
{
    protected $telegramService;
    protected $webhookToken = 'n0or614p5p0fs5b9jd9nx57te921wnqg';

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Обрабатывает входящие события от Bitrix24 через исходящий вебхук
     */
    public function handleEvent(Request $request)
    {
        // Подробное логирование всех входящих данных
        Log::info('Входящий GET-запрос от Bitrix24:', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'query_params' => $request->query(),
            'ip' => $request->ip()
        ]);

        // Проверяем токен из query параметров
        $token = $request->query('token') ?? $request->query('auth.application_token');

        Log::info('Проверка токена:', [
            'received_token' => $token,
            'expected_token' => $this->webhookToken
        ]);

        if ($token !== $this->webhookToken) {
            Log::warning('Неверный токен авторизации', [
                'ip' => $request->ip(),
                'token' => $token
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            // Проверяем, что это событие обновления сделки
            if ($request->query('event') === 'ONCRMDEALUPDATE') {
                // Получаем данные из query параметров
                $fields = $request->query('data.FIELDS', []);
                
                Log::info('Получены данные о сделке:', [
                    'event' => $request->query('event'),
                    'fields' => $fields,
                    'all_params' => $request->query()
                ]);

                // Проверяем изменение статуса сделки
                if (isset($fields['STAGE_ID'])) {
                    $dealId = $fields['ID'] ?? null;
                    $newStageId = $fields['STAGE_ID'];

                    Log::info('Обновление статуса сделки:', [
                        'deal_id' => $dealId,
                        'new_stage' => $newStageId
                    ]);

                    // Находим заказ по ID сделки в Bitrix24
                    $order = Order::where('bitrix_deal_id', $dealId)->first();

                    Log::info('Поиск заказа:', [
                        'deal_id' => $dealId,
                        'order_found' => !is_null($order),
                        'order_details' => $order ? [
                            'id' => $order->id,
                            'current_status' => $order->status,
                            'user_id' => $order->user_id
                        ] : null
                    ]);

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

                        Log::info('Маппинг статуса:', [
                            'bitrix_status' => $newStageId,
                            'mapped_status' => $statusMapping[$newStageId] ?? 'unknown'
                        ]);

                        $newStatus = $statusMapping[$newStageId] ?? 'unknown';
                        $oldStatus = $order->status;

                        if ($newStatus !== $oldStatus) {
                            // Обновляем статус заказа
                            $order->status = $newStatus;
                            $order->save();

                            Log::info('Статус заказа обновлен:', [
                                'order_id' => $order->id,
                                'old_status' => $oldStatus,
                                'new_status' => $newStatus
                            ]);

                            // Получаем русское название статуса для уведомления
                            $statusNames = [
                                'new' => 'Новый',
                                'processed' => 'В обработке',
                                'confirmed' => 'Подтвержден',
                                'shipped' => 'Отправлен',
                                'delivered' => 'Доставлен',
                                'completed' => 'Завершен',
                                'canceled' => 'Отменен'
                            ];

                            $statusText = $statusNames[$newStatus] ?? $newStatus;

                            // Формируем сообщение для пользователя
                            $message = "🔄 *Обновление статуса заказа #{$order->id}*\n\n";
                            $message .= "Новый статус: *{$statusText}*\n\n";

                            // Добавляем дополнительную информацию в зависимости от статуса
                            switch ($newStatus) {
                                case 'confirmed':
                                    $message .= "✅ Ваш заказ подтвержден и готовится к отправке.";
                                    break;
                                case 'shipped':
                                    $message .= "🚚 Ваш заказ передан в доставку.";
                                    break;
                                case 'delivered':
                                    $message .= "📦 Ваш заказ доставлен. Спасибо за покупку!";
                                    break;
                                case 'completed':
                                    $message .= "🎉 Заказ успешно выполнен. Спасибо за покупку!";
                                    break;
                                case 'canceled':
                                    $message .= "❌ Заказ отменен. Если у вас есть вопросы, пожалуйста, свяжитесь с нами.";
                                    break;
                            }

                            // Отправляем уведомление через Telegram
                            if ($order->user && $order->user->telegram_chat_id) {
                                Log::info('Отправка уведомления в Telegram:', [
                                    'chat_id' => $order->user->telegram_chat_id,
                                    'message' => $message
                                ]);

                                $this->telegramService->sendMessage(
                                    $order->user->telegram_chat_id,
                                    $message,
                                    ['parse_mode' => 'Markdown']
                                );
                            } else {
                                Log::warning('Не удалось отправить уведомление: отсутствует telegram_chat_id', [
                                    'order_id' => $order->id,
                                    'user_id' => $order->user_id ?? null
                                ]);
                            }
                        } else {
                            Log::info('Статус не изменился:', [
                                'order_id' => $order->id,
                                'status' => $oldStatus
                            ]);
                        }
                    } else {
                        Log::warning('Заказ не найден для сделки Bitrix24', [
                            'deal_id' => $dealId
                        ]);
                    }
                } else {
                    Log::info('Событие не содержит изменения статуса');
                }
            } else {
                Log::info('Получено неподдерживаемое событие:', [
                    'event' => $request->query('event')
                ]);
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
