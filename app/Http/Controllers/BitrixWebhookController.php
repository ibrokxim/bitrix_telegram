<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Services\Bitrix24\DealService;
use Illuminate\Support\Facades\Log;

class BitrixWebhookController extends Controller
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
        Log::info('Получен вебхук от Битрикс24:', $request->all());

        try {
            $data = $request->all();
            
            // Проверяем, что это событие изменения сделки
            if (!isset($data['event']) || $data['event'] !== 'ONCRMDEALUPDATE') {
                return response()->json(['status' => 'error', 'message' => 'Неверный тип события']);
            }

            // Проверяем авторизацию вебхука
            if (!$this->validateWebhook($data)) {
                return response()->json(['status' => 'error', 'message' => 'Неверная авторизация'], 403);
            }

            $dealId = $data['data']['FIELDS_AFTER']['ID'] ?? null;
            if (!$dealId) {
                return response()->json(['status' => 'error', 'message' => 'ID сделки не найден']);
            }

            // Получаем актуальную информацию о сделке
            $dealInfo = $this->dealService->getDeal($dealId);
            if (!$dealInfo) {
                return response()->json(['status' => 'error', 'message' => 'Не удалось получить информацию о сделке']);
            }

            $newStageId = $dealInfo['STAGE_ID'] ?? null;
            if (!$newStageId) {
                return response()->json(['status' => 'error', 'message' => 'Статус сделки не найден']);
            }

            // Находим заказ по ID сделки в Битрикс24
            $order = Order::where('bitrix_deal_id', $dealId)->first();
            if (!$order) {
                return response()->json(['status' => 'error', 'message' => 'Заказ не найден']);
            }

            // Маппинг статусов Битрикс24 на статусы заказа
            $statusMapping = [
                'NEW' => Order::STATUS_NEW,
                'PREPARATION' => Order::STATUS_PROCESSED,
                'PREPAYMENT_INVOICE' => Order::STATUS_CONFIRMED,
                'EXECUTING' => Order::STATUS_SHIPPED,
                'FINAL_INVOICE' => Order::STATUS_DELIVERED,
                'WON' => Order::STATUS_COMPLETED,
                'LOSE' => Order::STATUS_CANCELED
            ];

            $oldStatus = $order->status;
            $newStatus = $statusMapping[$newStageId] ?? $oldStatus;

            // Если статус действительно изменился
            if ($oldStatus !== $newStatus) {
                // Обновляем статус заказа
                $order->update(['status' => $newStatus]);

                // Отправляем уведомление в Telegram
                $this->telegramService->sendOrderStatusChangedNotification($order, $oldStatus, $newStatus);

                Log::info('Статус заказа успешно обновлен', [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'deal_id' => $dealId
                ]);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Ошибка при обработке вебхука от Битрикс24: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Проверка авторизации вебхука от Битрикс24
     */
    private function validateWebhook(array $data): bool
    {
        // Получаем секретный ключ из конфигурации
        $webhookSecret = config('services.bitrix24.webhook_secret');

        // Если секретный ключ не настроен, пропускаем проверку
        if (empty($webhookSecret)) {
            Log::warning('Секретный ключ вебхука не настроен');
            return true;
        }

        // Получаем подпись из заголовков
        $signature = request()->header('X-Bitrix-Webhook-Signature');
        if (empty($signature)) {
            Log::warning('Отсутствует подпись вебхука');
            return false;
        }

        // Формируем строку для проверки подписи
        $checkString = json_encode($data, JSON_UNESCAPED_SLASHES);
        
        // Вычисляем хеш
        $hash = hash_hmac('sha256', $checkString, $webhookSecret);

        // Сравниваем подписи
        return hash_equals($hash, $signature);
    }
} 