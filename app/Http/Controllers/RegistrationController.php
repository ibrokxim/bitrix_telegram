<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Validator;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Support\Facades\Log;

class RegistrationController extends Controller
{
    protected $bitrix24Service;
    protected $telegramService;

    public function __construct(Bitrix24Service $bitrix24Service, TelegramService $telegramService)
    {
        $this->bitrix24Service = $bitrix24Service;
        $this->telegramService = $telegramService;
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'second_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|string',
            'is_legal_entity' => 'boolean',
            'telegram_chat_id' => 'required|string', // Обязательное поле
            // Условные правила для юр. лиц
            'inn' => $request->input('is_legal_entity') ? 'required|string' : '',
            'company_name' => $request->input('is_legal_entity') ? 'required|string' : '',
            'position' => $request->input('is_legal_entity') ? 'required|string' : '',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Находим пользователя по telegram_chat_id
            $user = User::where('telegram_chat_id', $request->input('telegram_chat_id'))->first();

            if ($user) {
                $user->update([
                    'first_name' => $request->input('first_name'),
                    'second_name' => $request->input('second_name'),
                    'last_name' => $request->input('last_name'),
                    'phone' => $request->input('phone'),
                    'is_legal_entity' => $request->input('is_legal_entity', false),
                    'inn' => $request->input('inn'),
                    'company_name' => $request->input('company_name'),
                    'position' => $request->input('position'),
                    'status' => 'pending'
                ]);
            } else {
                $user = User::create([
                    'first_name' => $request->input('first_name'),
                    'second_name' => $request->input('second_name'),
                    'last_name' => $request->input('last_name'),
                    'phone' => $request->input('phone'),
                    'telegram_chat_id' => $request->input('telegram_chat_id'),
                    'is_legal_entity' => $request->input('is_legal_entity', false),
                    'inn' => $request->input('inn'),
                    'company_name' => $request->input('company_name'),
                    'position' => $request->input('position'),
                    'status' => 'pending'
                ]);
            }

            $this->sendTelegramNotification($user);

            return response()->json([
                'message' => 'Заявка успешно отправлена',
                'user_id' => $user->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при регистрации',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processUserRequest(Request $request)
    {
        $action = $request->input('action');
        $userId = $request->input('user_id');

        $user = User::findOrFail($userId);

        if ($action === 'approve') {
            $user->status = 'approved';
            $user->save();

            try {
                if ($user->is_legal_entity) {
                    // Сначала создаем контакт
                    $contactData = [
                        'NAME' => $user->first_name,
                        'LAST_NAME' => $user->second_name,
                        'SECOND_NAME' => $user->last_name,
                        'PHONE' => [['VALUE' => $user->phone, 'VALUE_TYPE' => 'WORK']],
                        'SOURCE_ID' => 'STORE',
                        'TYPE_ID' => 'CLIENT',
                        'COMMENTS' => 'Контактное лицо компании'
                    ];

                    $contactResponse = $this->bitrix24Service->createContact($contactData);

                    if ($contactResponse['status'] === 'error') {
                        throw new \Exception('Failed to create contact: ' . $contactResponse['message']);
                    }

                    // Затем создаем компанию
                    $companyData = [
                        'TITLE' => $user->company_name,
                        'COMPANY_TYPE' => 'CUSTOMER',
                        'INDUSTRY' => 'OTHER',
                        'INN' => $user->inn,
                        'PHONE' => [['VALUE' => $user->phone, 'VALUE_TYPE' => 'WORK']],
                        'COMMENTS' => 'Компания зарегистрирована через мини-приложение',
                    ];

                    $companyResponse = $this->bitrix24Service->createCompany($companyData);

                    if ($companyResponse['status'] === 'error') {
                        throw new \Exception('Failed to create company: ' . $companyResponse['message']);
                    }

                    // Связываем контакт с компанией
                    $bindResponse = $this->bitrix24Service->bindContactToCompany(
                        $contactResponse['contact_id'],
                        $companyResponse['company_id'],
                        [
                            'IS_PRIMARY' => 'Y',
                            'ROLE' => $user->position
                        ]
                    );

                    if ($bindResponse['status'] === 'error') {
                        throw new \Exception('Failed to bind contact to company: ' . $bindResponse['message']);
                    }

                    // Сохраняем ID в базе данных
                    $user->update([
                        'bitrix24_company_id' => $companyResponse['company_id'],
                        'bitrix24_contact_id' => $contactResponse['contact_id']
                    ]);

                } else {
                    // Для физического лица создаем только контакт
                    $contactData = [
                        'NAME' => $user->first_name,
                        'LAST_NAME' => $user->second_name,
                        'SECOND_NAME' => $user->last_name,
                        'PHONE' => [['VALUE' => $user->phone, 'VALUE_TYPE' => 'WORK']],
                        'SOURCE_ID' => 'STORE',
                        'TYPE_ID' => 'CLIENT',
                        'COMMENTS' => 'Клиент зарегистрирован через мини-приложение'
                    ];

                    $contactResponse = $this->bitrix24Service->createContact($contactData);

                    if ($contactResponse['status'] === 'success') {
                        $user->update([
                            'bitrix24_contact_id' => $contactResponse['contact_id']
                        ]);
                    } else {
                        throw new \Exception('Failed to create contact: ' . $contactResponse['message']);
                    }
                }

                $this->telegramService->sendApprovalMessage($user);

                return response()->json([
                    'message' => 'Пользователь одобрен',
                    'mini_app_link' => "https://t.me/kadyrov_urologbot/market"
                ]);

            } catch (\Exception $e) {
                \Log::error("Bitrix24 Integration Error: " . $e->getMessage(), [
                    'user_id' => $user->id,
                    'timestamp' => '2025-02-18 12:07:43',
                    'user' => 'ibrokxim'
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Ошибка при создании записей в Bitrix24: ' . $e->getMessage()
                ], 500);
            }
        } else {
            $user->status = 'rejected';
            $user->save();

            $this->telegramService->sendRejectionMessage($user);

            return response()->json([
                'message' => 'Пользователь отклонен'
            ]);
        }
    }

    private function sendTelegramNotification(User $user )
    {
        $message = "🆕 Новая заявка на доступ:\n\n" .
            "Имя: {$user->first_name}\n" .
            "Фамилия: {$user->second_name}\n" .
            "Отчество: {$user->last_name}\n" .
            "Телефон: {$user->phone}\n";

        if ($user->is_legal_entity) {
            $message .= "Юр. лицо: Да\n" .
                "ИНН: {$user->inn}\n" .
                "Компания: {$user->company_name}\n" .
                "Должность: {$user->position}\n";
        }

        $message .= "\nДействия:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ Принять',
                        'callback_data' => "approve_user_{$user->id}"
                    ],
                    [
                        'text' => '❌ Отклонить',
                        'callback_data' => "reject_user_{$user->id}"
                    ]
                ]
            ]
        ];

        $this->telegramService->sendMessageToAdminGroup($message, $keyboard);
    }

    public function verifyExistingUser(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required_without:inn|string',
                'inn' => 'required_without:phone|string',
                'telegram_chat_id' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = null;

            // Поиск по ИНН
            if ($request->has('inn')) {
                $user = User::where('inn', $request->inn)
                    ->where('status', 'approved')
                    ->first();
            }

            // Поиск по телефону, если пользователь еще не найден
            if (!$user && $request->has('phone')) {
                // Нормализуем телефон
                $phone = preg_replace('/[^0-9]/', '', $request->phone);
                
                $user = User::where(function($query) use ($phone) {
                    $query->where('phone', $phone)
                          ->orWhere('phone', '+' . $phone)
                          ->orWhere('phone', '998' . substr($phone, -9))
                          ->orWhere('phone', '+998' . substr($phone, -9));
                })
                ->where('status', 'approved')
                ->first();
            }

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Пользователь не найден в системе'
                ], 404);
            }

            // Обновляем telegram_chat_id
            $user->update(['telegram_chat_id' => $request->telegram_chat_id]);

            // Создаем токен для API
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'status' => 'approved',
                'message' => 'Пользователь успешно верифицирован',
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'second_name' => $user->second_name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'company_name' => $user->company_name,
                    'inn' => $user->inn,
                    'is_legal_entity' => $user->is_legal_entity,
                    'telegram_chat_id' => $user->telegram_chat_id,
                    'status' => $user->status
                ],
                'token' => $token
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при верификации пользователя: ' . $e->getMessage(), [
                'phone' => $request->phone ?? null,
                'inn' => $request->inn ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при проверке: ' . $e->getMessage()
            ], 500);
        }
    }

    public function listUsers()
    {
        try {
            $users = User::where('status', 'approved')->get();
            
            return response()->json([
                'status' => 'success',
                'users' => $users->map(function($user) {
                    return [
                        'id' => $user->id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'second_name' => $user->second_name,
                        'phone' => $user->phone,
                        'company_name' => $user->company_name,
                        'inn' => $user->inn,
                        'is_legal_entity' => $user->is_legal_entity,
                        'status' => $user->status,
                        'created_at' => $user->created_at
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка при получении списка пользователей: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при получении списка пользователей'
            ], 500);
        }
    }
}
