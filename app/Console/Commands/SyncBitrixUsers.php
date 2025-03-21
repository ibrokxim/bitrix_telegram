<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBitrixUsers extends Command
{
    protected $signature = 'bitrix:sync-users';
    protected $description = 'Синхронизация пользователей из Битрикс24';

    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        parent::__construct();
        $this->bitrix24Service = $bitrix24Service;
    }

    public function handle()
    {
        $this->info('Начинаем синхронизацию пользователей из Битрикс24...');

        try {
            // Получаем контакты
            $contacts = $this->bitrix24Service->getAllContacts();
            $this->info('Получено контактов: ' . count($contacts));

            foreach ($contacts as $contact) {
                $this->syncContact($contact);
            }

            // Получаем компании
            $companies = $this->bitrix24Service->getAllCompanies();
            $this->info('Получено компаний: ' . count($companies));

            foreach ($companies as $company) {
                $this->syncCompany($company);
            }

            $this->info('Синхронизация завершена успешно!');
        } catch (\Exception $e) {
            $this->error('Ошибка при синхронизации: ' . $e->getMessage());
            Log::error('Ошибка при синхронизации с Битрикс24: ' . $e->getMessage());
        }
    }

    protected function syncContact($contact)
    {
        $phone = $this->extractPhone($contact['PHONE'] ?? []);
        $email = $this->extractEmail($contact['EMAIL'] ?? []);

        if (!$phone) {
            return;
        }

        User::updateOrCreate(
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

    protected function syncCompany($company)
    {
        $phone = $this->extractPhone($company['PHONE'] ?? []);
        $email = $this->extractEmail($company['EMAIL'] ?? []);

        if (!$phone) {
            return;
        }

        User::updateOrCreate(
            ['bitrix_company_id' => $company['ID']],
            [
                'company_name' => $company['TITLE'] ?? '',
                'inn' => $company['UF_CRM_1708963492'] ?? null, // ID поля ИНН
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