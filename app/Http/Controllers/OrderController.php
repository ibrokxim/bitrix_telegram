<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Bitrix24Service;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
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
                'TITLE' => "Заказ #{$order->id} от {$user->first_name} {$user->last_name} ",
                'TYPE_ID' => 'SALE',
                'STAGE_ID' => 'NEW',
                'CURRENCY_ID' => 'UZS',
                'OPPORTUNITY' => $request->total_amount,
                'ASSIGNED_BY_ID' => 1,
                'CONTACT_ID' => $user->bitrix_contact_id,
                'PRODUCT_ROWS' => $this->formatProducts($request->cart),
                'COMMENTS' => json_encode([
                    'Источник' => 'Telegram бот',
                    'Пользователь' => $user->first_name . ' ' . $user->last_name,
                    'Телефон' => $user->phone,

                ])
            ];

            Log::info('Sending to Bitrix24:', $bitrixData);

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

            return response()->json([
                'status' => 'success',
                'order_id' => $order->id,
                'bitrix_deal_id' => $bitrixResponse['deal_id']
            ]);

        } catch (\Exception $e) {
            Log::error('Order Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function formatProducts(array $cart): array
    {
        return array_map(function ($item) {
            return [
                'PRODUCT_ID' => $item['id'],
                'PRODUCT_NAME' => $item['name'],
                'PRICE' => $item['price'],
                'QUANTITY' => $item['quantity']
            ];
        }, $cart);
    }

    public function checkAuth(Request $request)
    {
        $chatId = $request->input('chat_id');

        $user = User::where('telegram_chat_id', $chatId)->first();

        if ($user) {
            return response()->json([
                'status' => 'success',
                'user' => $user
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Пользователь не авторизован'
            ], 401);
        }
    }
}
