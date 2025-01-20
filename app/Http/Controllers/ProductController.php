<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Bitrix24Service;
use Illuminate\Support\Facades\Session;

class ProductController extends Controller
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
    }

    public function getCatalogs()
    {
        $catalogs = $this->bitrix24Service->getCatalogs();
        return response()->json($catalogs);
    }

    public function getProducts($sectionId)
    {
        $products = $this->bitrix24Service->getProducts($sectionId);

        if (isset($products['status']) && $products['status'] === 'error') {
            return response()->json([
                'message' => 'Ошибка при получении продуктов',
                'error' => $products['message']
            ], 500);
        }

        return [
            'products' => $products['products'],
            'total' => $products['total']
        ];
    }

    public function getProductById($id)
    {
        $product = $this->bitrix24Service->getProductById($id);
        return response()->json($product);
    }

    public function addToCart(Request $request, $productId)
    {
        $cart = Session::get('cart', []);
        $product = collect($this->bitrix24Service->getProducts($request->input('iblockId')))->firstWhere('ID', $productId);

        if ($product) {
            $cart[] = [
                'product_id' => $product['ID'],
                'name' => $product['NAME'],
                'price' => $product['PRICE'],
                'quantity' => $request->input('quantity', 1),
                'detail_picture' => $product['DETAIL_PICTURE'],
                'detail_text' => $product['DETAIL_TEXT']
            ];
            Session::put('cart', $cart);
        }

        return response()->json($cart);
    }

    // Просмотр корзины
    public function viewCart()
    {
        $cart = Session::get('cart', []);
        return response()->json($cart);
    }

    // Оформление заказа
    public function checkout(Request $request)
    {
        $cart = Session::get('cart', []);
        $total = collect($cart)->sum(function($item) {
            return $item['price'] * $item['quantity'];
        });

        // Применение скидки при превышении определенной суммы
        $discount = 0;
        if ($total > 1000) {  // Замените 1000 на ваше значение
            $discount = $total * 0.1;  // 10% скидка
            $total -= $discount;
        }

        $orderData = [
            'fields' => [
                'TITLE' => 'Order from API',
                'STAGE_ID' => 'NEW',
                'OPPORTUNITY' => $total,
                'CURRENCY_ID' => 'USD',
                'PRODUCT_ROWS' => array_map(function($item) {
                    return [
                        'PRODUCT_ID' => $item['product_id'],
                        'PRICE' => $item['price'],
                        'QUANTITY' => $item['quantity']
                    ];
                }, $cart),
                'CONTACT_ID' => $request->input('contact_id')
            ]
        ];

        $order = $this->bitrix24Service->addOrder($orderData);
        Session::forget('cart');  // Очистка корзины после оформления заказа

        return response()->json([
            'order' => $order,
            'discount' => $discount
        ]);
    }
}
