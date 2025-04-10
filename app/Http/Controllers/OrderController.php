<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Bitrix24Service;
use Illuminate\Support\Facades\Log;
use App\Services\TelegramService;

class OrderController extends Controller
{
    protected $bitrix24Service;
    protected $telegramService;

    public function __construct(Bitrix24Service $bitrix24Service, TelegramService $telegramService)
    {
        $this->bitrix24Service = $bitrix24Service;
        $this->telegramService = $telegramService;
    }

    public function placeOrder(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|string',
            'cart' => 'required|array',
            'total_amount' => 'required|numeric'
        ]);

        try {
            $user = User::where('telegram_chat_id', $request->chat_id)->firstOrFail();

            // Подсчитываем количество предыдущих заказов пользователя
            $ordersCount = Order::where('user_id', $user->id)->count();

            // Определяем CATEGORY_ID в зависимости от количества заказов
            $categoryId = $ordersCount === 0 ? 0 : 5; // 0 - Новые, 5 - Старые

            // Создаем заказ в БД
            $order = Order::create([
                'user_id' => $user->id,
                'total_amount' => $request->total_amount,
                'products' => json_encode($request->cart),
                'status' => 'new',
                'created_at' => now(),
                'updated_at' => now()
            ]);


            // Формируем данные для Битрикс24
            $bitrixData = [
                'TITLE' => "Заказ #{$order->id} от {$user->first_name} {$user->last_name}",
                'TYPE_ID' => 'SALE',
                'STAGE_ID' => 'NEW',
                'CATEGORY_ID' => $categoryId, // Устанавливаем нужную воронку
                'CURRENCY_ID' => 'UZS',
                'OPPORTUNITY' => $request->total_amount,
                'ASSIGNED_BY_ID' => 17,
                'CONTACT_ID' => $user->bitrix_contact_id,
                'PRODUCT_ROWS' => $this->formatProducts($request->cart),
                'COMMENTS' => $this->prepareDealComment($user)
            ];

            Log::info('Sending to Bitrix24:', [
                'data' => $bitrixData,
                'orders_count' => $ordersCount,
                'category_id' => $categoryId
            ]);

            // Создаем сделку
            $bitrixResponse = $this->bitrix24Service->createDeal($bitrixData);

            if ($bitrixResponse['status'] !== 'success') {
                throw new \Exception('Bitrix24 error: ' . ($bitrixResponse['message'] ?? 'Unknown error'));
            }

            // Обновляем заказ
            $order->update([
                'bitrix_deal_id' => $bitrixResponse['deal_id'],
                'status' => 'processed'
            ]);

            // Отправляем уведомление пользователю о создании заказа
            $this->telegramService->sendOrderCreatedNotification($order);

            return response()->json([
                'status' => 'success',
                'order_id' => $order->id,
                'bitrix_deal_id' => $bitrixResponse['deal_id'],
                'category' => $categoryId === 0 ? 'Новые' : 'Старые'
            ]);

        } catch (\Exception $e) {
            Log::error('Order Error: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function formatProducts(array $cart): array
    {
        return array_map(function ($item) {
            $product = [
                'PRODUCT_ID' => $item['id'],
                'PRODUCT_NAME' => $item['name'],
                'PRICE' => $item['price'],
                'QUANTITY' => $item['quantity'],
                'CURRENCY_ID' => 'UZS',
                'TAX_INCLUDED' => 'Y'  // Налог включен в цену
            ];

            // Если есть скидка
            if (isset($item['discount'])) {
                if (isset($item['discount']['type']) && $item['discount']['type'] === 'percent') {
                    $product['DISCOUNT_TYPE_ID'] = 2; // процентная скидка
                    $product['DISCOUNT_RATE'] = $item['discount']['value'];
                } else {
                    $product['DISCOUNT_TYPE_ID'] = 1; // фиксированная скидка
                    $product['DISCOUNT_SUM'] = $item['discount']['value'];
                }
            }

            // Если есть налог
            if (isset($item['tax_rate'])) {
                $product['TAX_RATE'] = $item['tax_rate'];
            }

            return $product;
        }, $cart);
    }

    public function checkAuth(Request $request)
    {
        try {
            $chatId = $request->input('chat_id');
            $phone = $this->normalizePhone($request->input('phone'));

            // Сначала проверяем по chat_id (для уже зарегистрированных)
            $user = User::where('telegram_chat_id', $chatId)->first();

            if ($user && $user->status === 'approved') {
                return response()->json([
                    'status' => 'success',
                    'is_registered' => true,
                    'user' => $this->formatUserData($user)
                ]);
            }

            // Если пользователь не найден по chat_id, ищем по телефону
            if ($phone) {
                $user = User::where(function($query) use ($phone) {
                    $query->where('phone', $phone)
                          ->orWhere('bitrix_phone', $phone);
                })->first();

                if ($user && $user->status === 'approved') {
                    // Обновляем telegram_chat_id для пользователя
                    $user->update(['telegram_chat_id' => $chatId]);

                    return response()->json([
                        'status' => 'success',
                        'is_registered' => true,
                        'user' => $this->formatUserData($user)
                    ]);
                }
            }

            return response()->json([
                'status' => 'success',
                'is_registered' => false
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при проверке авторизации: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при проверке авторизации'
            ], 500);
        }
    }

    protected function formatUserData($user)
    {
        return [
            'id' => $user->id,
            'status' => $user->status,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'company_name' => $user->company_name,
            'inn' => $user->inn,
            'is_legal_entity' => $user->is_legal_entity,
            'telegram_chat_id' => $user->telegram_chat_id
        ];
    }

    protected function normalizePhone($phone)
    {
        // Удаляем все кроме цифр
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Если номер начинается с 8, заменяем на 7
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }

        return $phone;
    }

    protected function prepareDealComment($user, $source = 'Telegram бот')
    {
        $comment = [
            'Источник' => $source,
            'Пользователь' => trim($user->first_name . ' ' . $user->last_name),
            'Телефон' => $user->phone,
            'Тип клиента' => $this->getClientType($user)
        ];

        return json_encode($comment, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function getClientType($user)
    {
        $ordersCount = Order::where('user_id', $user->id)->count();
        return $ordersCount > 0 ? 'Существующий клиент' : 'Новый клиент';
    }
}
