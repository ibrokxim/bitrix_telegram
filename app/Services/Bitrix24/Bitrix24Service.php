<?php

namespace App\Services\Bitrix24;

use Exception;

class Bitrix24Service
{
    private $catalogService;
    private $productService;
    private $contactService;
    private $companyService;
    private $dealService;
    private $legalEntityService;

    public function __construct(
        CatalogService $catalogService,
        ProductService $productService,
        ContactService $contactService,
        CompanyService $companyService,
        DealService $dealService,
        LegalEntityService $legalEntityService
    ) {
        $this->catalogService = $catalogService;
        $this->productService = $productService;
        $this->contactService = $contactService;
        $this->companyService = $companyService;
        $this->dealService = $dealService;
        $this->legalEntityService = $legalEntityService;
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
}
