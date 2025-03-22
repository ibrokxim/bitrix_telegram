<?php

namespace App\Services;

use App\Models\User;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Support\Facades\Log;

class UserVerificationService
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
    }

    public function verifyAndRegisterUser(string $phone)
    {
        try {
            $result = $this->bitrix24Service->findUserByPhone($phone);

            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Пользователь не найден в Битрикс24'
                ];
            }

            // Создаем или обновляем пользователя
            if ($result['type'] === 'contact') {
                $user = $this->createOrUpdateContact($result['data']);
            } else {
                $user = $this->createOrUpdateCompany($result['data']);
            }

            return [
                'success' => true,
                'message' => 'Пользователь успешно верифицирован',
                'user' => $user
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка при верификации пользователя: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Произошла ошибка при верификации: ' . $e->getMessage()
            ];
        }
    }

    protected function createOrUpdateContact(array $contact)
    {
        $phone = $this->extractPhone($contact['PHONE'] ?? []);
        $email = $this->extractEmail($contact['EMAIL'] ?? []);

        return User::updateOrCreate(
            ['bitrix_contact_id' => $contact['ID']],
            [
                'first_name' => $contact['NAME'] ?? '',
                'last_name' => $contact['LAST_NAME'] ?? '',
                'phone' => $phone,
                'bitrix_phone' => $phone,
                'email' => $email,
                'bitrix_email' => $email,
                'status' => 'approved',
                'last_sync_at' => now(),
            ]
        );
    }

    protected function createOrUpdateCompany(array $company)
    {
        $phone = $this->extractPhone($company['PHONE'] ?? []);
        $email = $this->extractEmail($company['EMAIL'] ?? []);

        return User::updateOrCreate(
            ['bitrix_company_id' => $company['ID']],
            [
                'company_name' => $company['TITLE'] ?? '',
                'inn' => $company['UF_CRM_1708963492'] ?? null,
                'is_legal_entity' => true,
                'phone' => $phone,
                'bitrix_phone' => $phone,
                'email' => $email,
                'bitrix_email' => $email,
                'status' => 'approved',
                'last_sync_at' => now(),
            ]
        );
    }

    protected function extractPhone($phones)
    {
        if (empty($phones)) {
            return null;
        }

        foreach ($phones as $phone) {
            if (!empty($phone['VALUE'])) {
                return $this->normalizePhone($phone['VALUE']);
            }
        }

        return null;
    }

    protected function extractEmail($emails)
    {
        if (empty($emails)) {
            return null;
        }

        foreach ($emails as $email) {
            if (!empty($email['VALUE'])) {
                return $email['VALUE'];
            }
        }

        return null;
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
} 