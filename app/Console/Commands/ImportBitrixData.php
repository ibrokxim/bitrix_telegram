<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use App\Services\Bitrix24\Bitrix24Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportBitrixData extends Command
{
    protected $signature = 'bitrix:import {--type=all : Тип импорта (all/contacts/companies)} {--mode=download : Режим работы (download/process)} {--delay=1 : Задержка между запросами в секундах}';
    protected $description = 'Импорт контактов и компаний из Bitrix24 в локальную БД';

    protected $bitrix24Service;
    protected $contactsFile = 'bitrix24/contacts.json';
    protected $companiesFile = 'bitrix24/companies.json';

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        parent::__construct();
        $this->bitrix24Service = $bitrix24Service;
    }

    public function handle()
    {
        $type = $this->option('type');
        $mode = $this->option('mode');
        $delay = (int)$this->option('delay');

        try {
            if (!Storage::exists('bitrix24')) {
                Storage::makeDirectory('bitrix24');
            }

            if ($mode === 'download') {
                $this->downloadData($type, $delay);
            } else {
                $this->processData($type);
            }

        } catch (\Exception $e) {
            $this->error('Ошибка при импорте: ' . $e->getMessage());
            Log::error('Ошибка при импорте данных из Битрикс24: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function downloadData($type, $delay)
    {
        if ($type === 'all' || $type === 'contacts') {
            $this->info('Загрузка контактов...');
            $this->downloadContacts($delay);
        }

        if ($type === 'all' || $type === 'companies') {
            $this->info('Загрузка компаний...');
            $this->downloadCompanies($delay);
        }

        $this->info('Загрузка данных завершена.');
    }

    protected function processData($type)
    {
        if ($type === 'all' || $type === 'contacts') {
            $this->info('Обработка контактов...');
            $this->processContacts();
        }

        if ($type === 'all' || $type === 'companies') {
            $this->info('Обработка компаний...');
            $this->processCompanies();
        }

        $this->info('Обработка данных завершена.');
    }

    protected function downloadContacts($delay)
    {
        $start = 0;
        $contacts = [];
        $hasMore = true;

        while ($hasMore) {
            try {
                $response = $this->bitrix24Service->makeRequest('crm.contact.list', [
                    'start' => $start,
                    'select' => ['*', 'PHONE', 'EMAIL']
                ]);

                if (!empty($response['result'])) {
                    $contacts = array_merge($contacts, $response['result']);
                    
                    // Проверяем, есть ли еще данные
                    $total = $response['total'] ?? 0;
                    $hasMore = ($start + 50) < $total;
                    $start += 50;

                    $this->info("Загружено контактов: " . count($contacts));
                    sleep($delay);
                } else {
                    $hasMore = false;
                }

            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'QUERY_LIMIT_EXCEEDED') !== false) {
                    $this->warn('Превышен лимит запросов. Ожидание 5 секунд...');
                    sleep(5);
                    continue;
                }
                throw $e;
            }
        }

        // Сохраняем все контакты в файл
        Storage::put($this->contactsFile, json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Загрузка контактов завершена. Всего контактов: ' . count($contacts));
    }

    protected function downloadCompanies($delay)
    {
        $start = 0;
        $companies = [];
        $hasMore = true;

        while ($hasMore) {
            try {
                $response = $this->bitrix24Service->makeRequest('crm.company.list', [
                    'start' => $start,
                    'select' => ['*', 'PHONE', 'EMAIL', 'UF_CRM_1708963492']
                ]);

                if (!empty($response['result'])) {
                    $companies = array_merge($companies, $response['result']);
                    
                    // Проверяем, есть ли еще данные
                    $total = $response['total'] ?? 0;
                    $hasMore = ($start + 50) < $total;
                    $start += 50;

                    $this->info("Загружено компаний: " . count($companies));
                    sleep($delay);
                } else {
                    $hasMore = false;
                }

            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'QUERY_LIMIT_EXCEEDED') !== false) {
                    $this->warn('Превышен лимит запросов. Ожидание 5 секунд...');
                    sleep(5);
                    continue;
                }
                throw $e;
            }
        }

        // Сохраняем все компании в файл
        Storage::put($this->companiesFile, json_encode($companies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Загрузка компаний завершена. Всего компаний: ' . count($companies));
    }

    protected function processContacts()
    {
        if (!Storage::exists($this->contactsFile)) {
            $this->error("Файл с контактами не найден. Сначала выполните загрузку данных.");
            return;
        }

        $contacts = json_decode(Storage::get($this->contactsFile), true);
        $bar = $this->output->createProgressBar(count($contacts));
        $bar->start();

        foreach ($contacts as $contactData) {
            try {
                // Получаем ID компании
                $companyId = $this->bitrix24Service->getContactCompany($contactData['ID']);
                $companyData = null;

                if ($companyId) {
                    $companyData = $this->bitrix24Service->getCompany($companyId);
                }

                User::updateOrCreate(
                    ['bitrix_contact_id' => $contactData['ID']],
                    [
                        'first_name' => $contactData['NAME'] ?? '',
                        'last_name' => $contactData['LAST_NAME'] ?? '',
                        'second_name' => $contactData['SECOND_NAME'] ?? '',
                        'phone' => $contactData['PHONE'][0]['VALUE'] ?? null,
                        'email' => $contactData['EMAIL'][0]['VALUE'] ?? null,
                        'status' => 'approved',
                        'last_sync_at' => now(),
                        'bitrix_company_id' => $companyId,
                        'company_name' => $companyData['TITLE'] ?? null,
                        'inn' => $companyData['UF_CRM_1708963492'] ?? null,
                        'is_legal_entity' => $companyId ? true : false
                    ]
                );

                $bar->advance();
            } catch (\Exception $e) {
                Log::error('Ошибка при обработке контакта: ' . $e->getMessage(), [
                    'contact_id' => $contactData['ID']
                ]);
            }
        }

        $bar->finish();
        $this->newLine();
    }

    protected function processCompanies()
    {
        if (!Storage::exists($this->companiesFile)) {
            $this->error("Файл с компаниями не найден. Сначала выполните загрузку данных.");
            return;
        }

        $companies = json_decode(Storage::get($this->companiesFile), true);
        $bar = $this->output->createProgressBar(count($companies));
        $bar->start();

        foreach ($companies as $companyData) {
            try {
                $contacts = $this->bitrix24Service->getCompanyContacts($companyData['ID']);

                if (!empty($contacts)) {
                    $primaryContact = $contacts[0];

                    User::updateOrCreate(
                        ['bitrix_company_id' => $companyData['ID']],
                        [
                            'company_name' => $companyData['TITLE'] ?? '',
                            'inn' => $companyData['UF_CRM_1708963492'] ?? null,
                            'is_legal_entity' => true,
                            'phone' => $companyData['PHONE'][0]['VALUE'] ?? null,
                            'email' => $companyData['EMAIL'][0]['VALUE'] ?? null,
                            'status' => 'approved',
                            'last_sync_at' => now(),
                            'first_name' => $primaryContact['NAME'] ?? '',
                            'last_name' => $primaryContact['LAST_NAME'] ?? '',
                            'second_name' => $primaryContact['SECOND_NAME'] ?? '',
                            'bitrix_contact_id' => $primaryContact['ID']
                        ]
                    );
                }

                $bar->advance();
            } catch (\Exception $e) {
                Log::error('Ошибка при обработке компании: ' . $e->getMessage(), [
                    'company_id' => $companyData['ID']
                ]);
            }
        }

        $bar->finish();
        $this->newLine();
    }
}
