<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function checkPhone(Request $request)
    {
        try {
            $phone = $this->formatPhone($request->phone);
            $user = User::where('phone', $phone)->first();

            if ($user) {
                // Отправляем уведомление в админскую группу
                $adminMessage = sprintf(
                    "🔵 Авторизация существующего клиента:\n\n" .
                    "👤 Имя: %s %s\n" .
                    "📱 Телефон: %s\n" .
                    "🆔 ID: %s\n" .
                    "📅 Дата регистрации: %s",
                    $user->first_name,
                    $user->last_name,
                    $user->phone,
                    $user->id,
                    $user->created_at->format('d.m.Y H:i')
                );

                $this->telegramService->sendMessageToAdmin($adminMessage);

                Log::info('Существующий пользователь авторизовался', [
                    'user_id' => $user->id,
                    'phone' => $user->phone
                ]);

                return response()->json([
                    'exists' => true,
                    'user' => $user
                ]);
            }

            return response()->json([
                'exists' => false
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при проверке телефона: ' . $e->getMessage(), [
                'phone' => $request->phone ?? 'не указан',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Произошла ошибка при проверке телефона'
            ], 500);
        }
    }

    protected function formatPhone($phone)
    {
        // Удаляем все, кроме цифр
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Если номер начинается с 8, заменяем на +7
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }

        // Добавляем + в начало, если его нет
        if ($phone[0] !== '+') {
            $phone = '+' . $phone;
        }

        return $phone;
    }
} 