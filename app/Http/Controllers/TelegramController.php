<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function handleWebhook(Request $request)
    {
        // Логируем входящие данные
        Log::info('Вебхук получен:', $request->all());

        $update = $request->all();

        // Обработка команды /start
        if (isset($update['message']['text']) && $update['message']['text'] === '/start') {
            $chatId = $update['message']['chat']['id'];
            Log::info('Обработка команды /start для chat_id:', ['chat_id' => $chatId]);

            try {
                $this->telegramService->handleStartCommand($chatId);
                Log::info('Команда /start успешно обработана для chat_id:', ['chat_id' => $chatId]);
            } catch (\Exception $e) {
                Log::error('Ошибка при обработке команды /start:', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
