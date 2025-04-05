<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24\DealService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Bitrix24WebhookController extends Controller
{
    protected $dealService;
    protected $telegramService;

    public function __construct(DealService $dealService, TelegramService $telegramService)
    {
        $this->dealService = $dealService;
        $this->telegramService = $telegramService;
    }

    public function handleDealUpdate(Request $request)
    {
        try {
            Log::info('Webhook data received', ['data' => $request->all()]);

            $dealId = $request->input('data.FIELDS.ID');
            if (!$dealId) {
                throw new \Exception('Deal ID not found in webhook data');
            }

            $deal = $this->dealService->getDeal($dealId);
            if (!$deal) {
                throw new \Exception('Deal not found: ' . $dealId);
            }

            // Формируем сообщение для Telegram
            $message = "🔔 Обновление статуса заказа\n\n";
            $message .= "📦 Заказ №{$dealId}\n";
            $message .= "📊 Новый статус: {$deal['STAGE_ID']}\n";

            // Отправляем уведомление в Telegram
            $this->telegramService->sendMessageToAdmin($message);

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error($e->getMessage(), [
                'deal_id' => $dealId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}