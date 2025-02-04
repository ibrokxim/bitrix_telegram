<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Bitrix24Service;

class OrderController extends Controller
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
    }

    public function placeOrder(Request $request)
    {
        $chatId = $request->input('chat_id'); // ID чата в Telegram
        $cartItems = $request->input('cart'); // Товары из корзины
        $totalAmount = $request->input('total_amount'); // Общая сумма заказа

        // Находим пользователя по chat_id
        $user = User::where('telegram_chat_id', $chatId)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Пользователь не авторизован'
            ], 401);
        }

        // Создаем заказ в базе данных
        $order = Order::create([
            'user_id' => 2,
            'total_amount' => $totalAmount,
            'products' => json_encode($cartItems),
            'status' => 'pending'
        ]);

        // Создаем заказ в Битрикс24
        $bitrixOrderData = [
            'TITLE' => "Заказ #{$order->id} от {$user->first_name}",
            'CONTACT_ID' => $user->bitrix_contact_id, // ID контакта в Битрикс24 (если есть)
            'ASSIGNED_BY_ID' => 1, // ID ответственного
            'OPPORTUNITY' => $totalAmount, // Сумма заказа
            'CURRENCY_ID' => 'USD', // Валюта
            'PRODUCT_ROWS' => $this->formatProductsForBitrix($cartItems) // Товары в заказе
        ];

        $bitrixResponse = $this->bitrix24Service->createDeal($bitrixOrderData);

        if ($bitrixResponse['status'] === 'error') {
            \Log::error("Ошибка при создании заказа в Битрикс24: " . $bitrixResponse['message']);
            return response()->json(['message' => 'Ошибка при создании заказа'], 500);
        }

        return response()->json([
            'message' => 'Заказ успешно оформлен',
            'order_id' => $order->id,
            'bitrix_deal_id' => $bitrixResponse['deal_id']
        ]);
    }

    private function formatProductsForBitrix(array $cartItems)
    {
        $products = [];
        foreach ($cartItems as $item) {
            $products[] = [
                'PRODUCT_NAME' => $item['name'],
                'PRICE' => $item['price'],
                'QUANTITY' => $item['quantity']
            ];
        }
        return $products;
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
