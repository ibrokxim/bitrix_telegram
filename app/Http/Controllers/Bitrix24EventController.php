<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TelegramService;
use App\Services\Bitrix24\DealService;
use App\Services\Bitrix24\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Bitrix24EventController extends Controller
{
    protected $webhookToken;
    protected $dealService;
    protected $productService;
    protected $telegramService;

    public function __construct(
        DealService $dealService,
        ProductService $productService,
        TelegramService $telegramService
    ) {
        $this->webhookToken = "a2m41hryq1h0h4239z8j2m6qbetoz62a";
        $this->dealService = $dealService;
        $this->productService = $productService;
        $this->telegramService = $telegramService;
    }

    /**
     * Обрабатывает входящие события от Bitrix24 через исходящий вебхук
     */
    public function handleEvent(Request $request)
    {
        // Подробное логирование всех входящих данных
        Log::info('Входящий запрос от Bitrix24:', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
            'ip' => $request->ip()
        ]);

        // Проверяем токен из разных источников
        $token = $request->header('X-Bitrix-Webhook-Token')
            ?? $request->input('token')
            ?? $request->input('auth.application_token');

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
            if ($request->input('event') === 'ONCRMDEALUPDATE') {
                $fields = $request->input('data.FIELDS', []);

                Log::info('Получены данные о сделке:', [
                    'event' => $request->input('event'),
                    'fields' => $fields
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
                            $message = "📦 *Заказ #{$order->id}* | *Buyurtma #{$order->id}*\n\n";

                            // Добавляем информацию о товарах
                            $message .= "*Состав заказа:*\n";
                            $message .= "*Buyurtma tarkibi:*\n";
                            foreach ($order->items as $item) {
                                $message .= "• {$item->product->name} x {$item->quantity} шт. = {$item->price} сум\n";
                            }
                            $message .= "\n";

                            // Получаем товары сделки из Битрикс24
                            $dealProducts = $this->productService->getDealProducts($dealId);
                            if ($dealProducts) {
                                $message .= "*Товары в Битрикс24:*\n";
                                $message .= "*Bitrix24 dagi tovarlar:*\n";
                                foreach ($dealProducts as $product) {
                                    $message .= "• {$product['PRODUCT_NAME']} x {$product['QUANTITY']} шт. = {$product['PRICE']} сум\n";
                                }
                                $message .= "\n";
                            }

                            // Добавляем информацию в зависимости от статуса
                            switch ($newStatus) {
                                case 'new':
                                    $message .= "🆕 *Статус заказа: Новый*\n";
                                    $message .= "Ваш заказ успешно создан и принят в обработку. В ближайшее время с вами свяжется наш менеджер.\n\n";
                                    $message .= "🆕 *Buyurtma holati: Yangi*\n";
                                    $message .= "Buyurtmangiz muvaffaqiyatli yaratildi va qayta ishlashga qabul qilindi. Tez orada menejerimiz siz bilan bog'lanadi.";
                                    break;
                                case 'processed':
                                    $message .= "⚡️ *Статус заказа: В обработке*\n";
                                    $message .= "Мы начали обрабатывать ваш заказ. Наши специалисты проверяют наличие товаров и готовят их к отправке.\n\n";
                                    $message .= "⚡️ *Buyurtma holati: Qayta ishlashda*\n";
                                    $message .= "Buyurtmangizni qayta ishlashni boshladik. Mutaxassislarimiz tovarlar mavjudligini tekshirib, jo'natishga tayyorlamoqdalar.";
                                    break;
                                case 'confirmed':
                                    $message .= "✅ *Статус заказа: Подтвержден*\n";
                                    $message .= "Ваш заказ подтвержден! Мы подготовили все товары и готовим их к отправке. Ожидайте информацию о доставке.\n\n";
                                    $message .= "✅ *Buyurtma holati: Tasdiqlandi*\n";
                                    $message .= "Buyurtmangiz tasdiqlandi! Barcha tovarlarni tayyorladik va jo'natishga tayyorlamoqdamiz. Yetkazib berish haqida ma'lumot kuting.";
                                    break;
                                case 'shipped':
                                    $message .= "🚚 *Статус заказа: Отправлен*\n";
                                    $message .= "Отличные новости! Ваш заказ уже в пути. Курьер доставит его по указанному адресу в ближайшее время.\n\n";
                                    $message .= "🚚 *Buyurtma holati: Jo'natildi*\n";
                                    $message .= "Ajoyib yangilik! Buyurtmangiz yo'lda. Kuryer uni ko'rsatilgan manzilga yaqin vaqt ichida yetkazib beradi.";
                                    break;
                                case 'delivered':
                                    $message .= "📬 *Статус заказа: Доставлен*\n";
                                    $message .= "Ваш заказ успешно доставлен! Спасибо за покупку. Надеемся, вам понравятся приобретенные товары.\n\n";
                                    $message .= "📬 *Buyurtma holati: Yetkazildi*\n";
                                    $message .= "Buyurtmangiz muvaffaqiyatli yetkazib berildi! Xarid uchun rahmat. Sotib olingan tovarlar sizga yoqadi degan umiddamiz.";
                                    break;
                                case 'completed':
                                    $message .= "🎉 *Статус заказа: Завершен*\n";
                                    $message .= "Заказ успешно завершен! Благодарим вас за выбор нашего магазина. Будем рады видеть вас снова!\n\n";
                                    $message .= "🎉 *Buyurtma holati: Yakunlandi*\n";
                                    $message .= "Buyurtma muvaffaqiyatli yakunlandi! Do'konimizni tanlaganingiz uchun tashakkur. Sizni yana ko'rishdan xursand bo'lamiz!";
                                    break;
                                case 'canceled':
                                    $message .= "❌ *Статус заказа: Отменен*\n";
                                    $message .= "К сожалению, ваш заказ был отменен. Если у вас возникли вопросы, пожалуйста, свяжитесь с нашей службой поддержки.\n\n";
                                    $message .= "❌ *Buyurtma holati: Bekor qilindi*\n";
                                    $message .= "Afsuski, buyurtmangiz bekor qilindi. Savollaringiz bo'lsa, iltimos, bizning qo'llab-quvvatlash xizmatimiz bilan bog'laning.";
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
                    'event' => $request->input('event')
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
