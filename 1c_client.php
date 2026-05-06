<?php
/**
 * Клиент для работы с вебхуками 1С
 * Все запросы через Raw cURL для максимальной надёжности
 */
namespace Integration;

use Bitrix\Main\Web\HttpClient;

class OneCClient {
    private $baseUrl;
    private $login;
    private $password;
    
    public function __construct($config) {
        $this->baseUrl = rtrim($config['1c_base_url'], '/');
        $this->login = $config['1c_auth']['login'];
        $this->password = $config['1c_auth']['password'];
    }
    
    // ===== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ =====
    
    /**
     * Универсальный cURL запрос к 1С
     * @param string $url URL запроса
     * @param string $method GET или POST
     * @param array|string|null $payload Данные для POST
     * @param int $timeout Таймаут в секундах
     * @return array ['success'=>bool, 'http_code'=>int, 'response'=>string, 'error'=>string]
     */
    private function sendRequest($url, $method = 'GET', $payload = null, $timeout = 30) {
        // Санитизация URL
        $cleanUrl = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($url));
        $cleanUrl = preg_replace('#([^:])//+#', '$1/', $cleanUrl);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $cleanUrl,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'Expect:'  // Отключаем 100-continue
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        
        if ($method === 'POST' && $payload !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        $curlErrno = curl_errno($curl);
        curl_close($curl);
        
        return [
            'success' => ($curlErrno === 0 && $httpCode === 200),
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $curlErrno !== 0 ? "cURL #{$curlErrno}: {$curlError}" : ($httpCode !== 200 ? "HTTP {$httpCode}" : '')
        ];
    }
    
    /**
     * GET запрос к 1С
     */
    private function getRequest($url, $timeout = 30) {
        return $this->sendRequest($url, 'GET', null, $timeout);
    }
    
    /**
     * POST запрос к 1С
     */
    private function postRequest($url, $payload, $timeout = 30) {
        return $this->sendRequest($url, 'POST', $payload, $timeout);
    }
    
    // ===== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ДЛЯ БП =====
    
    /**
     * Получение значения для поля "Оплата получена" по логическому флагу
     * @param bool $paid true = "Да", false = "Нет"
     * @param array $config конфигурация
     * @return string ID элемента списка
     */
    public function getPaymentReceivedValue($paid, $config) {
        return $paid
            ? $config['payment_received']['values']['yes']  // '260'
            : $config['payment_received']['values']['no'];  // '261'
    }
    
    // ===== МЕТОДЫ ДЛЯ ПОЛУЧЕНИЯ ДАННЫХ (GET) =====
    
    /**
     * Получение контрагентов из 1С
     */
    public function getAllPartners($onlyModified = true) {
        $url = $this->baseUrl . '/partners/';
        Logger::info('Запрос контрагентов из 1С', ['url' => $url]);
        
        $result = $this->getRequest($url, 120);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения контрагентов из 1С', [
                'url' => $url,
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'], 0, 300)
            ]);
            return false;
        }
        
        $partners = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($partners)) {
            Logger::error('Ошибка парсинга JSON контрагентов', [
                'json_error' => json_last_error_msg(),
                'response_preview' => mb_substr($result['response'], 0, 500)
            ]);
            return false;
        }
        
        Logger::info('Контрагентов получено из 1С', ['count' => count($partners)]);
        return $partners;
    }
    
    /**
     * Получение товаров из 1С
     */
    public function getAllItems($mode = '1', $productCode = '', $limit = 10000) {
        // Загрузка одного товара по коду
        if ($mode === '0' && !empty($productCode)) {
            $url = $this->baseUrl . '/items/0/' . urlencode($productCode);
            Logger::debug('Запрос конкретного товара из 1С', ['url' => $url]);
            
            $result = $this->getRequest($url, 30);
            
            if ($result['success']) {
                $item = json_decode($result['response'], true);
                return is_array($item) ? [$item] : false;
            }
            
            Logger::error('Ошибка получения товара', [
                'url' => $url,
                'http_code' => $result['http_code'],
                'error' => $result['error']
            ]);
            return false;
        }
        
        // Загрузка списка товаров
        $url = $this->baseUrl . '/items/' . $mode . '/0';
        Logger::info('Запрос списка товаров из 1С', ['url' => $url, 'mode' => $mode]);
        
        $result = $this->getRequest($url, 120);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения товаров из 1С', [
                'url' => $url,
                'mode' => $mode,
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'], 0, 300)
            ]);
            return false;
        }
        
        $items = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
            Logger::error('Ошибка парсинга JSON товаров', [
                'json_error' => json_last_error_msg(),
                'response_preview' => mb_substr($result['response'], 0, 500)
            ]);
            return false;
        }
        
        Logger::info('Товара получено из 1С', ['count' => count($items), 'mode' => $mode]);
        return $items;
    }
    
    /**
     * Получение списка счетов на оплату
     */
    public function getInvoices($dnkId = '0') {
        $url = $this->baseUrl . '/orders/0/' . urlencode($dnkId);
        $result = $this->getRequest($url, 30);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения списка счетов', [
                'dnk_id' => $dnkId,
                'http_code' => $result['http_code'],
                'response' => mb_substr($result['response'], 0, 200)
            ]);
            return false;
        }
        
        $data = json_decode($result['response'], true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Получение PDF счета на оплату
     */
    public function getInvoicePdf($dnkId) {
        $url = $this->baseUrl . '/orders/pf/' . urlencode($dnkId);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf',
                'Expect:'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($curl);
        curl_close($curl);
        
        if ($httpCode === 200 && 
            (stripos($contentType, 'application/pdf') !== false || 
             stripos($contentType, 'octet-stream') !== false ||
             stripos($response, '%PDF-') === 0)) {
            Logger::info('PDF счета успешно получен', ['dnk_id' => $dnkId]);
            return $response;
        }
        
        Logger::error('Ошибка получения PDF счета', [
            'dnk_id' => $dnkId,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'error' => $curlError
        ]);
        return false;
    }
    
	/**
	 * Получение заказа покупателя из 1С по DNK_id (ID сделки Б24)
	 * Запрос к /CustomerOrder/
	 * 
	 * @param string|int $dnkId ID сделки из Битрикс24
	 * @return array|false Массив с данными заказа или false, если не найден
	 */
	public function getOrderByDnkId($dnkId) {
		try {
			$baseUrl = $this->baseUrl;
			$login = $this->login;
			$password = $this->password;
			$url = rtrim($baseUrl, '/') . '/CustomerOrder';
			
			Logger::debug('Запрос заказа покупателя из 1С по DNK_id', [
				'url' => $url,
				'dnk_id' => $dnkId
			]);
			
			// Используем getRequest() для единообразия
			$result = $this->getRequest($url, 60);  // ↑ увеличенный таймаут, т.к. заказов может быть много
			
			if (!$result['success']) {
				Logger::error('Ошибка получения заказов из 1С', [
					'dnk_id' => $dnkId,
					'http_code' => $result['http_code'],
					'error' => $result['error']
				]);
				return false;
			}
			
			$orders = @json_decode($result['response'], true);
			if (!is_array($orders)) {
				Logger::debug('Ответ 1С не является массивом', [
					'dnk_id' => $dnkId,
					'type' => gettype($orders)
				]);
				return false;
			}
			
			Logger::debug('Получено заказов из 1С', [
				'dnk_id' => $dnkId,
				'count' => count($orders)
			]);
			
			// Ищем заказ, где DNK_id совпадает с ID сделки
			$targetDnk = (string)$dnkId;
			foreach ($orders as $order) {
				if (!is_array($order)) continue;
				
				// Поддерживаем несколько возможных названий поля
				$orderDnkId = (string)($order['DNK_id'] 
					?? $order['DNK'] 
					?? $order['Битрикс24_Сделка'] 
					?? $order['external_id'] 
					?? '');
				
				// Строгое сравнение строк + проверка, что заказ проведён
				if ($orderDnkId === $targetDnk && !empty($order['Номер']) && ($order['Проведен'] ?? '') === 'Да') {
					Logger::info('Заказ покупателя найден в 1С', [
						'dnk_id' => $dnkId,
						'order_number' => $order['Номер'],
						'order_uid' => $order['УИД'] ?? $order['ИДЗаписи'] ?? '',
						'is_posted' => $order['Проведен'] ?? 'Нет',
						'is_paid' => $order['Оплачен'] ?? 'Нет'
					]);
					return $order;
				}
			}
			
			// Не нашли заказ с таким DNK_id
			Logger::debug('Заказ покупателя не найден в 1С', [
				'dnk_id' => $dnkId,
				'total_orders_scanned' => count($orders)
			]);
			return false;
			
		} catch (\Exception $e) {
			Logger::error('Исключение в getOrderByDnkId', [
				'dnk_id' => $dnkId,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}
    
    /**
     * Проверка статуса оплаты по заказу в 1С
     * @param string|int $dnkId Внешний ID заказа (ID сделки Б24)
     * @return array|false ['paid'=>bool, 'amount_paid'=>float, ...] или false
     */
    public function checkPaymentStatus($dnkId) {
        try {
            // Используем getOrderByDnkId, а не getInvoices!
            $order = $this->getOrderByDnkId($dnkId);
            if (!$order) return false;
            
            // Поле "Оплачен" в заказе (а не в счете!)
            $isPaid = ($order['Оплачен'] ?? 'Нет') === 'Да';
            $amountPaid = (float)($order['СуммаОплаты'] ?? 0);
            
            Logger::debug('Статус оплаты из 1С', [
                'dnk_id' => $dnkId,
                'paid_raw' => $order['Оплачен'] ?? 'EMPTY',
                'is_paid' => $isPaid,
                'amount_paid' => $amountPaid
            ]);
            
            return [
                'paid' => $isPaid,
                'amount_paid' => $amountPaid,
                'invoice_number' => $order['НомерСчетаНаОплату'] ?? '',
                'invoice_uid' => $order['УИДСчетаНаОплату'] ?? '',
                'is_posted' => ($order['Проведен'] ?? 'Нет') === 'Да'
            ];
        } catch (\Exception $e) {
            Logger::error('Ошибка проверки статуса оплаты', [
                'dnk_id' => $dnkId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Проверка статуса оплаты по СЧЕТУ в 1С
     */
    public function checkInvoicePaymentStatus($dnkId) {
        $url = rtrim($this->baseUrl, '/') . '/orders/0/0';
        Logger::debug('Проверка статуса оплаты счета', ['url' => $url, 'dnk_id' => $dnkId]);
        
        $result = $this->getRequest($url, 30);
        
        if (!$result['success']) {
            return false;
        }
        
        $invoices = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($invoices)) {
            return false;
        }
        
        foreach ($invoices as $item) {
            if (isset($item['DNK_id']) && (string)$item['DNK_id'] == (string)$dnkId) {
                $isPaidFlag = ($item['Оплачен'] ?? 'Нет') === 'Да';
                $amountPaid = (float)($item['Оплачено'] ?? $item['СуммаОплаты'] ?? $item['СуммаАванса'] ?? 0);
                $isPaid = $isPaidFlag || $amountPaid > 0;
                
                return [
                    'paid' => $isPaid,
                    'amount_paid' => $amountPaid,
                    'invoice_number' => $item['Номер'] ?? '',
                    'invoice_uid' => $item['УИД'] ?? '',
                    'is_posted' => ($item['Проведен'] ?? 'Нет') === 'Да'
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Получение списка банковских счетов из 1С
     */
    public function getBankAccounts() {
        $url = $this->baseUrl . '/BankAccount/';
        Logger::debug('Запрос банковских счетов из 1С', ['url' => $url]);
        
        $result = $this->getRequest($url, 30);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения банковских счетов', [
                'url' => $url,
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        if (empty($result['response'])) {
            return [];
        }
        
        $accounts = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($accounts)) {
            Logger::error('Ошибка парсинга JSON банковских счетов', [
                'json_error' => json_last_error_msg(),
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        Logger::info('Банковских счетов получено', ['count' => count($accounts)]);
        return $accounts;
    }
    
    /**
     * Получение списка касс из 1С
     */
    public function getCashboxes() {
        $url = $this->baseUrl . '/cashbox/';
        Logger::debug('Запрос касс из 1С', ['url' => $url]);
        
        $result = $this->getRequest($url, 30);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения касс', [
                'url' => $url,
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        if (empty($result['response'])) {
            return [];
        }
        
        $cashboxes = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($cashboxes)) {
            Logger::error('Ошибка парсинга JSON касс', [
                'json_error' => json_last_error_msg(),
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        Logger::info('Касс получено', ['count' => count($cashboxes)]);
        return $cashboxes;
    }
    
    /**
     * Получение списка организаций из 1С
     */
    public function getOrganizations() {
        $url = $this->baseUrl . '/organization/';
        Logger::debug('Запрос организаций из 1С', ['url' => $url]);
        
        $result = $this->getRequest($url, 30);
        
        if (!$result['success']) {
            Logger::error('Ошибка получения организаций', [
                'url' => $url,
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        if (empty($result['response'])) {
            return [];
        }
        
        $organizations = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($organizations)) {
            Logger::error('Ошибка парсинга JSON организаций', [
                'json_error' => json_last_error_msg(),
                'response_preview' => mb_substr($result['response'] ?? '', 0, 300)
            ]);
            return false;
        }
        
        Logger::info('Организаций получено из 1С', ['count' => count($organizations)]);
        return $organizations;
    }
    
    // ===== МЕТОДЫ ДЛЯ СОЗДАНИЯ ДОКУМЕНТОВ (POST) =====
    
    /**
     * Создание заказа покупателя в 1С
     */
    public function createCustomerOrder($deal, $invoiceParams = '') {
        // Нормализация параметров
        $invoiceData = [];
        if (is_array($invoiceParams)) {
            $invoiceData = $invoiceParams;
        } elseif (!empty($invoiceParams) && is_string($invoiceParams)) {
            $invoiceData = ['УИДСчетаНаОплату' => $invoiceParams];
        }
        
        // Получение УИД организации
        $organizationUuid = '';
        $organizationFieldCode = 'UF_CRM_1773266651';
        
        $dbDeal = \CCrmDeal::GetList(
            [],
            ['=ID' => (int)$deal['ID']],
            ['ID', 'TITLE', 'CATEGORY_ID', 'UF_CRM_1773266651'],
            false,
            ['nTopCount' => 1]
        );
        $fullDeal = $dbDeal->Fetch();
        
        if ($fullDeal && !empty($fullDeal[$organizationFieldCode])) {
            $rawValue = $fullDeal[$organizationFieldCode];
            $organizationElementId = null;
            if (is_array($rawValue)) {
                $val = $rawValue['VALUE'] ?? null;
                if (is_array($val)) $val = reset($val);
                if ($val !== null) $organizationElementId = (int)$val;
            } else {
                $organizationElementId = (int)$rawValue;
            }
            
            if ($organizationElementId && \Bitrix\Main\Loader::includeModule('iblock')) {
                $dbProp = \CIBlockElement::GetProperty(59, $organizationElementId, ['sort' => 'asc'], ['ID' => 1096]);
                $prop = $dbProp->Fetch();
                if ($prop && !empty($prop['VALUE'])) {
                    $organizationUuid = trim((string)$prop['VALUE']);
                }
            }
        }
        
        $invoiceUid = !empty($invoiceData['guid_order']) ? $invoiceData['guid_order'] : (is_string($invoiceParams) ? $invoiceParams : '');
        
        $payload = [[
            'id' => (string)$deal['ID'],
            'date' => (new \DateTime($deal['DATE_CREATE'] ?? 'now'))->format('Y-m-d\TH:i:s'),
            'inn' => $deal['COMPANY_INN'] ?? '',
            'kpp' => $deal['COMPANY_KPP'] ?? '',
            'id_partner' => $deal['1C_PARTNER_ID'] ?? '',
            'payName' => '',
            'NDS' => (int)($deal['VAT_RATE'] ?? 20),
            'guid_order' => $invoiceUid ?? '',
            'organization' => $organizationUuid,
            'structure' => $organizationUuid,
            'items' => $this->prepareOrderItems($deal['PRODUCT_ROWS'] ?? []),
        ]];
        
        $url = rtrim($this->baseUrl, '/') . '/CustomerOrder/';
        Logger::debug('Создание заказа покупателя в 1С', ['url' => $url, 'payload' => $payload[0]]);
        
        $result = $this->postRequest($url, $payload, 30);
        
        if (!$result['success']) {
            Logger::error('Ошибка создания заказа покупателя', [
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'response_preview' => mb_substr($result['response'], 0, 300)
            ]);
            return ['success' => false, 'error' => $result['error']];
        }
        
        if (empty(trim($result['response']))) {
            return ['success' => true, 'order_number' => '', 'warning' => '1С вернула пустой ответ'];
        }
        
        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'JSON parse error: ' . json_last_error_msg()];
        }
        
        $order = is_array($data) ? reset($data) : $data;
        if (empty($order['Номер'])) {
            return ['success' => false, 'error' => 'Заказ не создан: ' . ($order['Ошибка'] ?? 'неизвестная ошибка')];
        }
        
        $parse1CDate = fn($d) => $d ? preg_replace('/\s+\d+:\d+:\d+$/', '', trim($d)) : '';
        
        return [
            'success' => true,
            'order_number' => $order['Номер'],
            'invoice_number' => $order['НомерСчетаНаОплату'] ?? $invoiceData['НомерСчетаНаОплату'] ?? '',
            'invoice_date' => $parse1CDate($order['ДатаСчетаНаОплату'] ?? $invoiceData['ДатаСчетаНаОплату'] ?? ''),
            'invoice_uid' => $order['УИДСчетаНаОплату'] ?? $invoiceData['УИДСчетаНаОплату'] ?? '',
            '1c_record_id' => $order['ИДЗаписи'] ?? '',
            'is_posted' => ($order['Проведен'] ?? 'Нет') === 'Да',
            'is_paid' => ($order['Оплачен'] ?? 'Нет') === 'Да'
        ];
    }
    
    /**
     * Создание расхода со счета (безналичный)
     */
    public function createBankPayment($dealId, $bankAccountId, $amount, $supplierOrderId = '') {
        $payload = [[
            'id' => (string)$dealId,
            'date' => date('Y-m-d\TH:i:sP'),
            'id_Order' => $supplierOrderId ?: '',
            'id_order_acc' => (string)$dealId,
            'inn' => '',
            'kpp' => '',
            'id_partner' => '',
            'BankAccount' => $bankAccountId,
            'amount' => (float)$amount,
            'operation' => '1'
        ]];
        
        $url = rtrim($this->baseUrl, '/') . '/Payment/';
        Logger::debug('Создание расхода со счета', ['url' => $url, 'payload' => $payload[0]]);
        
        $result = $this->postRequest($url, $payload, 30);
        
        if (!$result['success']) {
            Logger::error('cURL error в createBankPayment', [
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'url' => $url
            ]);
            return ['success' => false, 'error' => $result['error']];
        }
        
        if (empty(trim($result['response']))) {
            return ['success' => true, 'document_number' => '', 'warning' => '1С вернула пустой ответ'];
        }
        
        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'JSON parse error: ' . json_last_error_msg()];
        }
        
        $doc = is_array($data) ? reset($data) : $data;
        
        return [
            'success' => true,
            'document_number' => $doc['Номер'] ?? $doc['document_number'] ?? '',
            'document_uid' => $doc['УИД'] ?? '',
            'raw_response' => $doc
        ];
    }
    
    /**
     * Создание расхода из кассы (наличный)
     */
    public function createCashPayment($dealId, $cashboxId, $amount, $supplierOrderId = '') {
        $payload = [[
            'id' => (string)$dealId,
            'date' => date('Y-m-d\TH:i:sP'),
            'id_Order' => $supplierOrderId ?: '',
            'id_order_acc' => (string)$dealId,
            'inn' => '',
            'kpp' => '',
            'id_partner' => '',
            'cashbox' => $cashboxId,
            'amount' => (float)$amount,
            'operation' => '1'
        ]];
        
        $url = rtrim($this->baseUrl, '/') . '/CashPayment/';
        Logger::debug('Создание расхода из кассы', ['url' => $url, 'payload' => $payload[0]]);
        
        $result = $this->postRequest($url, $payload, 30);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }
        
        if (empty(trim($result['response']))) {
            return ['success' => true, 'document_number' => ''];
        }
        
        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'JSON parse error'];
        }
        
        $doc = is_array($data) ? reset($data) : $data;
        
        return [
            'success' => true,
            'document_number' => $doc['Номер'] ?? $doc['document_number'] ?? '',
            'document_uid' => $doc['УИД'] ?? '',
            'raw_response' => $doc
        ];
    }
    
	/**
	 * Создание заказа поставщику в 1С
	 */
	public function createSupplierOrder($data) {
		$payload = [[
			'id' => $data['id'] ?? '',
			'id_custumerOrder' => $data['id_custumerOrder'] ?? '',
			'date' => $data['date'] ?? date('c'),
			'receipt_date' => $data['receipt_date'] ?? date('Y-m-d'),
			'inn' => $data['inn'] ?? '',
			'kpp' => $data['kpp'] ?? '',
			'id_partner' => $data['id_partner'] ?? '',
			'payName' => '',
			'type' => $data['type'] ?? '',
			'status' => $data['status'] ?? '',
			'IncNumber' => $data['IncNumber'] ?? '',
			'IncDate' => $data['IncDate'] ?? '',
			'NDS' => $data['NDS'] ?? '1',
			'organization' => !empty($data['organization']) ? $data['organization'] : '',
			'structure' => !empty($data['structure']) ? $data['structure'] : '',
			'store' => !empty($data['store']) ? $data['store'] : '',
			'items' => $data['items'] ?? [],
		]];
		
		$url = rtrim($this->baseUrl, '/') . '/OrderSupplier/';
		Logger::debug('Создание заказа поставщику в 1С', ['url' => $url, 'payload' => $payload[0]]);
		
		$result = $this->postRequest($url, $payload, 30);
		
		if (!$result['success']) {
			Logger::error('cURL error в createSupplierOrder', [
				'http_code' => $result['http_code'],
				'error' => $result['error'],
				'url' => $url
			]);
			return ['success' => false, 'error' => $result['error']];
		}
		
		// ОБРАБОТКА ПУСТОГО ОТВЕТА
		if (empty(trim($result['response']))) {
			Logger::warning('1С вернула пустой ответ при создании заказа поставщику', [
				'deal_id' => $data['id'] ?? 'unknown',
				'note' => 'Пытаемся получить документ отдельным запросом'
			]);
			
			usleep(500000); // 500 мс пауза перед повторным запросом
			
			$createdDoc = $this->fetchCreatedSupplierOrder($data['id'] ?? '');
			if ($createdDoc && !empty($createdDoc['Номер'])) {
				Logger::info('Документ найден через fallback', [
					'deal_id' => $data['id'],
					'number' => $createdDoc['Номер']
				]);
				return [
					'success' => true,
					'document_number' => $createdDoc['Номер'],
					'document_uid' => $createdDoc['УИД'] ?? '',
					'document_date' => $createdDoc['Дата'] ?? '',
					'fallback_used' => true
				];
			}

			return [
				'success' => false,
				'error' => '1С не вернула номер документа (обработка отложена)'
			];
		}
		
		// ОБРАБОТКА ОТВЕТА С ОТСУТСТВИЕМ НОМЕРА
		$doc = json_decode($result['response'], true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['success' => false, 'error' => 'JSON parse error: ' . json_last_error_msg()];
		}
		
		$doc = is_array($doc) ? reset($doc) : $doc;
		
		if (empty($doc['Номер'])) {
			Logger::warning('В ответе 1С отсутствует поле "Номер"', [
				'deal_id' => $data['id'] ?? 'unknown',
				'response_sample' => array_slice($doc, 0, 5)
			]);
			return [
				'success' => false,
				'error' => '1С не вернула номер документа (обработка отложена)'
			];
		}

		// Успешный ответ с номером
		return [
			'success' => true,
			'document_number' => $doc['Номер'],
			'document_uid' => $doc['УИД'] ?? '',
			'document_date' => $doc['Дата'] ?? '',
			'raw_response' => $doc
		];
	}
    
    // ===== FALLBACK МЕТОДЫ =====
    
    /**
     * Fallback: получение созданного заказа поставщику
     */
    public function fetchCreatedSupplierOrder($dealId) {
        $url = rtrim($this->baseUrl, '/') . '/OrderSupplier';
        Logger::debug('Fallback-запрос списка заказов поставщику', ['url' => $url, 'deal_id' => $dealId]);
        
        $result = $this->getRequest($url, 15);
        
        if (!$result['success']) {
            Logger::error('Ошибка GET OrderSupplier (fallback)', [
                'http_code' => $result['http_code'],
                'deal_id' => $dealId
            ]);
            return false;
        }
        
        $documents = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($documents)) {
            return false;
        }
        
        $targetDnk = (string)$dealId;
        foreach ($documents as $doc) {
            if (!is_array($doc)) continue;
            $docDnk = (string)($doc['DNK_id'] ?? '');
            $docNumber = $doc['Номер'] ?? '';
            
            if ($docDnk === $targetDnk && !empty($docNumber)) {
                Logger::info('Заказ поставщику найден через fallback', [
                    'deal_id' => $dealId,
                    'doc_number' => $docNumber
                ]);
                return [
                    'Номер' => $docNumber,
                    'УИД' => $doc['УИД'] ?? '',
                    'Дата' => $doc['Дата'] ?? ''
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Fallback: получение созданного расхода
     */
    public function fetchCreatedPayment($dealId, $type) {
        $endpoint = $type === 'cash' ? '/CashPayment' : '/Payment';
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        Logger::debug('Fallback-запрос списка расходов', ['url' => $url, 'deal_id' => $dealId, 'type' => $type]);
        
        $result = $this->getRequest($url, 15);
        
        if (!$result['success']) {
            return false;
        }
        
        $documents = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($documents)) {
            return false;
        }
        
        $targetDnk = (string)$dealId;
        foreach ($documents as $doc) {
            if (!is_array($doc)) continue;
            $docDnk = (string)($doc['DNK_id'] ?? '');
            $docNumber = $doc['Номер'] ?? '';
            
            if ($docDnk === $targetDnk && !empty($docNumber)) {
                Logger::info('Расход найден через fallback', [
                    'deal_id' => $dealId,
                    'doc_number' => $docNumber
                ]);
                return [
                    'Номер' => $docNumber,
                    'Дата' => $doc['Дата'] ?? '',
                    'УИД' => $doc['УИД'] ?? ''
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Проверка существующего расхода в 1С
     */
    public function checkExistingPayment($dealId, $type) {
        $endpoint = $type === 'cash' ? '/CashPayment/' : '/Payment/';
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        Logger::debug('Проверка существующего расхода в 1С', ['url' => $url, 'deal_id' => $dealId, 'type' => $type]);
        
        $result = $this->getRequest($url, 15);
        
        if (!$result['success']) {
            return false;
        }
        
        $documents = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($documents)) {
            return false;
        }
        
        $targetDnk = (string)$dealId;
        foreach ($documents as $doc) {
            if (!is_array($doc)) continue;
            $docDnk = (string)($doc['DNK_id'] ?? '');
            $docNumber = $doc['Номер'] ?? '';
            
            if ($docDnk === $targetDnk && !empty($docNumber)) {
                Logger::info('Расход уже существует в 1С', [
                    'deal_id' => $dealId,
                    'doc_number' => $docNumber,
                    'type' => $type
                ]);
                return [
                    'Номер' => $docNumber,
                    'Дата' => $doc['Дата'] ?? '',
                    'УИД' => $doc['УИД'] ?? '',
                    'Проведен' => $doc['Проведен'] ?? 'Нет'
                ];
            }
        }
        
        return false;
    }
    
    // ===== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ =====
    
    /**
     * Подготовка товаров для заказа покупателя
     */
    private function prepareOrderItems($productRows) {
        $items = [];
        $key = 1;
        
        foreach ($productRows as $row) {
            $productId = $row['PRODUCT_ID'] ?? 0;
            if (!$productId) continue;
            
            $offerId = $this->getProductCode1C($productId);
            if (empty($offerId)) {
                Logger::warning('Товар пропущен: не заполнен КОД номенклатуры 1С', [
                    'product_id' => $productId,
                    'product_name' => $row['PRODUCT_NAME'] ?? ''
                ]);
                continue;
            }
            
            $basePrice = (float)($row['PRICE'] ?? $row['BASE_PRICE'] ?? 0);
            $finalPrice = (float)($row['DISCOUNT_PRICE'] ?? $row['PRICE'] ?? 0);
            $discount = $basePrice - $finalPrice;
            $discountPercent = $basePrice > 0 ? round(($discount / $basePrice * 100), 2) : 0;
            
            $items[] = [
                'key' => $key++,
                'offer_Id' => $offerId,
                'lot' => 'f819f5f2-1cdd-11ea-8116-0050569b6607',
                'quantity' => (float)($row['QUANTITY'] ?? 1),
                'basePrice' => $basePrice,
                'finalPrice' => $finalPrice,
                'discountsPercent' => $discountPercent,
                'discountsPrice' => $discount,
            ];
        }
        
        Logger::debug('Товары подготовлены для 1С', ['count' => count($items)]);
        return $items;
    }
    
    /**
     * Получение КОДА номенклатуры 1С из свойства товара
     */
    private function getProductCode1C($productId) {
        try {
            $res = \CIBlockElement::GetProperty(14, $productId, ['sort' => 'asc'], ['ID' => 1085]);
            $prop = $res->Fetch();
            if (!empty($prop['VALUE'])) {
                return trim($prop['VALUE']);
            }
            
            global $DB;
            $sql = "SELECT VALUE FROM b_iblock_property_epv WHERE IBLOCK_PROPERTY_ID = 1085 AND IBLOCK_ELEMENT_ID = " . (int)$productId . " AND PROPERTY_VALUE_ID IS NOT NULL LIMIT 1";
            $dbRes = $DB->Query($sql);
            $row = $dbRes->Fetch();
            if (!empty($row['VALUE'])) {
                return trim($row['VALUE']);
            }
            
            return '';
        } catch (\Exception $e) {
            Logger::error('Ошибка получения кода номенклатуры 1С', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
    
    /**
     * Получение ИНН компании из сделки
     */
    public function getCompanyInn($dealData) {
        $companyId = $dealData['COMPANY_ID'] ?? 0;
        if (!$companyId) return '';
        
        $company = \CCrmCompany::GetByID($companyId);
        if (!$company) return '';
        
        $requisite = \CCrmCompany::GetRequisite($companyId);
        if (!empty($requisite) && is_array($requisite)) {
            foreach ($requisite as $req) {
                if (!empty($req['RQ_INN'])) {
                    return $req['RQ_INN'];
                }
            }
        }
        return '';
    }
    
    /**
     * Получение КПП компании из сделки
     */
    public function getCompanyKpp($dealData) {
        $companyId = $dealData['COMPANY_ID'] ?? 0;
        if (!$companyId) return '';
        
        $company = \CCrmCompany::GetByID($companyId);
        if (!$company) return '';
        
        $requisite = \CCrmCompany::GetRequisite($companyId);
        if (!empty($requisite) && is_array($requisite)) {
            foreach ($requisite as $req) {
                if (!empty($req['RQ_KPP'])) {
                    return $req['RQ_KPP'];
                }
            }
        }
        return '';
    }

	/**
	 * Получение списка пользователей из 1С
	 * @return array|false
	 */
	public function getAllUsers() {
		try {
			$url = rtrim($this->baseUrl, '/') . '/Users';
			
			$result = $this->getRequest($url);
			
			if (!$result['success']) {
				Logger::error('Ошибка получения пользователей из 1С', [
					'url' => $url,
					'http_code' => $result['http_code'],
					'error' => $result['error']
				]);
				return false;
			}
			
			$users = json_decode($result['response'], true);
			return is_array($users) ? $users : [];
			
		} catch (\Exception $e) {
			Logger::error('Исключение при получении пользователей', [
				'error' => $e->getMessage()
			]);
			return false;
		}
	}
	
	/**
	 * Получение списка групп доступа из 1С
	 * @return array|false
	 */
	public function getAllGroups() {
		try {
			$url = rtrim($this->baseUrl, '/') . '/group';
			
			$result = $this->getRequest($url);
			
			if (!$result['success']) {
				Logger::error('Ошибка получения групп из 1С', [
					'url' => $url,
					'http_code' => $result['http_code'],
					'error' => $result['error']
				]);
				return false;
			}
			
			$groups = json_decode($result['response'], true);
			return is_array($groups) ? $groups : [];
			
		} catch (\Exception $e) {
			Logger::error('Исключение при получении групп', [
				'error' => $e->getMessage()
			]);
			return false;
		}
	}
	
	/**
	 * Создание пользователя в 1С
	 * @param array $usersData Массив объектов пользователей (как требует 1С)
	 * @return array ['success' => bool, 'uid' => string, 'error' => string]
	 */
	public function createUser($usersData) {
		try {
			$url = rtrim($this->baseUrl, '/') . '/Users';
			
			// 🔹 Логируем отправляемые данные (для отладки)
			Logger::debug('Отправка данных пользователя в 1С', [
				'url' => $url,
				'payload' => $usersData
			]);
			
			// 🔹 POST запрос с массивом данных
			$result = $this->postRequest($url, $usersData);
			
			// 🔹 Логируем ответ 1С даже при ошибке
			Logger::debug('Ответ 1С на создание пользователя', [
				'http_code' => $result['http_code'],
				'response_body' => $result['response'],
				'curl_error' => $result['error']
			]);
			
			if (!$result['success']) {
				Logger::error('Ошибка создания пользователя в 1С', [
					'http_code' => $result['http_code'],
					'1c_error_body' => $result['response'],
					'curl_error' => $result['error']
				]);
				return [
					'success' => false,
					'error' => $result['error'] ?: "HTTP {$result['http_code']}",
					'1c_response' => $result['response']
				];
			}
			
			// 🔹 Парсим ответ: 1С может вернуть массив созданных пользователей
			$response = json_decode($result['response'], true);
			
			// Извлекаем УИД первого созданного пользователя (если есть)
			$uid = '';
			if (is_array($response)) {
				$first = reset($response);
				$uid = $first['УИД'] ?? $first['id'] ?? $first['guid'] ?? '';
			}
			
			return [
				'success' => true,
				'uid' => $uid,
				'data' => $response
			];
			
		} catch (\Exception $e) {
			Logger::error('Исключение при создании пользователя', [
				'error' => $e->getMessage()
			]);
			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}
}