<?php

namespace App\Services\Bitrix24;

use Exception;
use Illuminate\Support\Facades\Log;

class Bitrix24Service
{
    private $catalogService;
    private $productService;
    private $contactService;
    private $companyService;
    private $dealService;
    private $legalEntityService;
    private $client;
    private $webhookUrl;

    public function __construct(
        CatalogService $catalogService,
        ProductService $productService,
        ContactService $contactService,
        CompanyService $companyService,
        DealService $dealService,
        LegalEntityService $legalEntityService,
        $client,
        $webhookUrl
    ) {
        $this->catalogService = $catalogService;
        $this->productService = $productService;
        $this->contactService = $contactService;
        $this->companyService = $companyService;
        $this->dealService = $dealService;
        $this->legalEntityService = $legalEntityService;
        $this->client = $client;
        $this->webhookUrl = $webhookUrl;
    }

    // Методы каталога
    public function getCatalogs()
    {
        return $this->catalogService->getCatalogs();
    }

    // Методы продуктов
    public function getProducts($sectionId)
    {
        return $this->productService->getProducts($sectionId);
    }

    public function getProductById($id)
    {
        return $this->productService->getProductById($id);
    }

    public function clearProductImagesCache($productId)
    {
        return $this->productService->clearProductImagesCache($productId);
    }

    // Методы контактов
    public function createContact(array $fields)
    {
        return $this->contactService->createContact($fields);
    }

    public function checkContactExists($phone)
    {
        return $this->contactService->checkContactExists($phone);
    }

    // Методы компаний
    public function createCompany(array $fields)
    {
        return $this->companyService->createCompany($fields);
    }

    public function checkCompanyExists($inn)
    {
        return $this->companyService->checkCompanyExists($inn);
    }

    public function bindContactToCompany($contactId, $companyId, array $additionalFields = [])
    {
        return $this->companyService->bindContactToCompany($contactId, $companyId, $additionalFields);
    }

    // Методы сделок
    public function createDeal(array $dealData)
    {
        return $this->dealService->createDeal($dealData);
    }

    public function createLead(array $leadData)
    {
        return $this->dealService->createLead($leadData);
    }

    public function updateLeadStatus($leadId, $status)
    {
        return $this->dealService->updateLeadStatus($leadId, $status);
    }

    public function addOrder($orderData)
    {
        return $this->dealService->addOrder($orderData);
    }

    // Методы юридических лиц
    public function createLegalEntity(array $data)
    {
        try {
            return $this->legalEntityService->createLegalEntity($data);
        } catch (Exception $e) {
            \Log::error('Error in createLegalEntity: ' . $e->getMessage(), [
                'data' => $data,
                'timestamp' => '2025-02-22 12:18:15',
                'user' => 'ibrokxim',
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // Вспомогательные методы
    public function getWebhookUrl()
    {
        return $this->catalogService->getWebhookUrl();
    }

    public function getCacheTimeout()
    {
        return $this->catalogService->getCacheTimeout();
    }

    // Метод для обработки общих ошибок
    private function handleError(Exception $e, string $operation, array $context = [])
    {
        $errorContext = array_merge($context, [
            'operation' => $operation,
            'timestamp' => '2025-02-22 12:18:15',
            'user' => 'ibrokxim',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        \Log::error("Bitrix24 {$operation} Error", $errorContext);

        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'operation' => $operation
        ];
    }

    // Метод для проверки успешности ответа
    private function isSuccessResponse($response)
    {
        return isset($response['status']) && $response['status'] === 'success';
    }

    // Метод для логирования успешных операций
    private function logSuccess(string $operation, array $data = [])
    {
        \Log::info("Bitrix24 {$operation} Success", array_merge($data, [
            'timestamp' => '2025-02-22 12:18:15',
            'user' => 'ibrokxim'
        ]));
    }

    // Метод для форматирования данных перед отправкой
    private function formatRequestData(array $data)
    {
        return array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    // Метод для валидации обязательных полей
    private function validateRequiredFields(array $data, array $required)
    {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new Exception('Missing required fields: ' . implode(', ', $missing));
        }

        return true;
    }

    public function getAllContacts()
    {
        $contacts = [];
        $start = 0;

        do {
            $response = $this->makeRequest('crm.contact.list', [
                'start' => $start,
                'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'EMAIL']
            ]);

            if (!empty($response['result'])) {
                $contacts = array_merge($contacts, $response['result']);
                $start += 50; // Битрикс24 возвращает по 50 записей
            }
        } while (!empty($response['result']));

        return $contacts;
    }

    public function getAllCompanies()
    {
        $companies = [];
        $start = 0;

        do {
            $response = $this->makeRequest('crm.company.list', [
                'start' => $start,
                'select' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'UF_CRM_1708963492'] // UF_CRM_1708963492 - поле ИНН
            ]);

            if (!empty($response['result'])) {
                $companies = array_merge($companies, $response['result']);
                $start += 50;
            }
        } while (!empty($response['result']));

        return $companies;
    }

    public function findUserByPhone($phone)
    {
        // Нормализуем телефон
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        
        // Форматы для поиска
        $searchFormats = [
            $normalizedPhone,
            '+' . $normalizedPhone,
            '998' . substr($normalizedPhone, -9),
            '+998' . substr($normalizedPhone, -9)
        ];
        
        // Поиск среди контактов
        foreach ($searchFormats as $phoneFormat) {
            $contactResponse = $this->makeRequest('crm.contact.list', [
                'filter' => [
                    'PHONE' => $phoneFormat
                ],
                'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'EMAIL']
            ]);

            if (!empty($contactResponse['result'])) {
                $contact = $contactResponse['result'][0];
                return [
                    'type' => 'contact',
                    'data' => $contact,
                    'found_by' => $phoneFormat
                ];
            }
        }

        // Поиск среди компаний
        foreach ($searchFormats as $phoneFormat) {
            $companyResponse = $this->makeRequest('crm.company.list', [
                'filter' => [
                    'PHONE' => $phoneFormat
                ],
                'select' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'UF_CRM_1708963492']
            ]);

            if (!empty($companyResponse['result'])) {
                $company = $companyResponse['result'][0];
                return [
                    'type' => 'company',
                    'data' => $company,
                    'found_by' => $phoneFormat
                ];
            }
        }

        return null;
    }

    protected function makeRequest($method, $params = [])
    {
        $url = $this->webhookUrl . $method;
        
        try {
            $response = $this->client->post($url, [
                'json' => $params
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Ошибка запроса к Битрикс24: ' . $e->getMessage(), [
                'method' => $method,
                'params' => $params
            ]);
            throw $e;
        }
    }
}
