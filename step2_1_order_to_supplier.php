<?php
/**
 * Шаг 2.1: Заказ покупателя → Заказ поставщику
 * Создаёт заказ поставщику в 1С после успешного создания заказа покупателя
 *
 * Триггеры:
 * - Розничный отдел: стадия "В обработке" (EXECUTING)
 * - Оптовый отдел: стадия "Передано в отгрузку" (C1:1)
 *
 * Условия:
 * 1. Поле UF_CRM_1C_ORDER_ID заполнено (заказ покупателя создан в 1С)
 * 2. Поле UF_CRM_1C_SUPPLIER_ORDER_ID пустое (заказ поставщику ещё не создан)
 *
 * Направление: Битрикс24 → 1С
 * Эндпоинт 1С: /OrderSupplier/
 */

// ФИКС ДЛЯ CLI: определяем DOCUMENT_ROOT ДО любого require
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");

// Авторизация как администратор для корректной работы фильтров Битрикс в CLI
if (PHP_SAPI === 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        $USER = new \CUser();
        $USER->Authorize(1);  // ID=1 — администратор по умолчанию
        // Или ваш пользователь: $USER->Authorize(6);
    }
}

// Дальше можно закрывать сессию, если нужно
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('crm');
\Bitrix\Main\Loader::includeModule('catalog');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

// Ручная проверка авторизации
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

// === ЗАГРУЗКА КОНФИГУРАЦИИ ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
$oneC = new OneCClient($config);
Logger::init($config);

// === ПРОВЕРКА ДОСТУПА ===
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

Logger::info('=== ЗАПУСК ШАГА 2.1: Создание заказов поставщику в 1С ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : $USER->GetID()
]);

// === ПОЛУЧЕНИЕ СПИСКА СДЕЛОК ДЛЯ ОБРАБОТКИ ===
$dealsToProcess = getDealsForSupplierOrder($config);

if (empty($dealsToProcess)) {
    Logger::info('Нет сделок для создания заказов поставщику');
    echo json_encode(
        ['success' => true, 'processed' => 0, 'message' => 'Нет сделок для обработки'], 
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

Logger::info('Найдено сделок для обработки', ['count' => count($dealsToProcess)]);

// === ОБРАБОТКА КАЖДОЙ СДЕЛКИ ===
$processedCount = 0;
$createdCount = 0;
$failedCount = 0;
$failures = [];

foreach ($dealsToProcess as $deal) {
    try {
        $dealId = $deal['ID'];
        $customerOrderNumber = $deal[$config['deal_fields']['UF_CRM_1C_ORDER_ID']] ?? '';
        $existingSupplierOrders = $deal[$config['deal_fields']['UF_CRM_1C_SUPPLIER_ORDER_ID']] ?? '';
        
        Logger::info('Обработка сделки для заказа поставщику', [
            'deal_id' => $dealId,
            'customer_order' => $customerOrderNumber,
            'existing_supplier_orders' => $existingSupplierOrders ?: 'не созданы'
        ]);
        
        // Проверка: заказ покупателя должен существовать в 1С
        if (empty($customerOrderNumber) || $customerOrderNumber === '0') {
            Logger::warning('Пропуск: заказ покупателя не создан в 1С', ['deal_id' => $dealId]);
            continue;
        }

        // Шаг 1: Получение УИД заказа покупателя из 1С (для привязки)
        $customerOrderUid = getCustomerOrderUidFrom1C($oneC, $dealId, $customerOrderNumber, $config);
        if (empty($customerOrderUid)) {
            Logger::warning('Не удалось получить УИД заказа покупателя из 1С', ['deal_id' => $dealId]);
            continue;
        }
        
        Logger::debug('УИД заказа покупателя получен', [
            'deal_id' => $dealId,
            'customer_order_uid' => $customerOrderUid
        ]);
        
        // Шаг 2: Получение товаров сделки
        $products = getDealProducts($dealId);
        if (empty($products)) {
            throw new \Exception('В сделке отсутствуют товары');
        }
        
		// Шаг 3: Группировка товаров по поставщику и создание заказов
		
		// 3.1: Собираем информацию о каждом товаре + его поставщике
		$productsWithSuppliers = [];
		
		foreach ($products as $product) {
			$productId = $product['PRODUCT_ID'] ?? 0;
			
			// Получаем УИД поставщика из свойства товара (1212)
			$supplierUid = getSupplierUidFromProduct($productId);
			
			// Ищем компанию в Б24 по этому УИД
			$company = findCompanyBySupplierUid($supplierUid, $config);
			$partnerId = $company ? $company['id'] : '';
			
			// Логирование, если поставщик не найден
			if (empty($partnerId) && !empty($supplierUid)) {
				Logger::warning('Поставщик не найден в Б24, товар будет в заказе без id_partner', [
					'product_id' => $productId,
					'supplier_uid' => $supplierUid
				]);
			}
			
			Logger::debug('Поставщик для товара', [
				'product_id' => $productId,
				'product_name' => $product['PRODUCT_NAME'] ?? '',
				'supplier_uid' => $supplierUid,
				'partner_id' => $partnerId,
				'partner_name' => $company['title'] ?? 'not found'
			]);
			
			// Добавляем в массив с группировкой по partner_id
			// Ключ: "8638" или "6716" или "" (если не найден)
			$groupKey = $partnerId ?: 'NO_PARTNER_' . md5($supplierUid ?: uniqid());
			
			if (!isset($productsWithSuppliers[$groupKey])) {
				$productsWithSuppliers[$groupKey] = [
					'partner_id' => $partnerId,
					'partner_name' => $company['title'] ?? '',
					'supplier_uid' => $supplierUid,
					'products' => []
				];
			}
			
			$productsWithSuppliers[$groupKey]['products'][] = $product;
		}
		
		Logger::debug('Товары сгруппированы по поставщикам', [
			'deal_id' => $dealId,
			'groups_count' => count($productsWithSuppliers),
			'partners' => array_map(fn($g) => $g['partner_name'] ?: $g['partner_id'], $productsWithSuppliers)
		]);
		
		// 3.2: Создаём ОДИН заказ для КАЖДОГО уникального поставщика
		$createdOrders = [];
		$duplicateCount = 0;
		
		foreach ($productsWithSuppliers as $groupKey => $group) {
			try {
				$partnerId = $group['partner_id'];
				$partnerName = $group['partner_name'];
				$groupProducts = $group['products'];
				
				// Подготовка данных для заказа (несколько товаров)
				$supplierOrderData = prepareSupplierOrderDataMultipleItems(
					$deal, 
					$groupProducts, 
					$customerOrderUid, 
					$partnerId, 
					$config
				);
				
				if (!$supplierOrderData) {
					Logger::warning('Не удалось подготовить данные заказа для поставщика', [
						'partner_id' => $partnerId,
						'deal_id' => $dealId
					]);
					continue;
				}
				
				// Создание заказа поставщику в 1С
				$result = $oneC->createSupplierOrder($supplierOrderData);
				
				if (!$result['success']) {
					$errorMsg = $result['error'] ?? 'Неизвестная ошибка 1С';
					
					// Если 1С вернула ошибку дубликата — это ожидаемое поведение
					// Проверяем по комбинации: id_custumerOrder + все offer_Id в заказе
					$offerIds = array_column($supplierOrderData['items'], 'offer_Id');
					$duplicateCheck = $dealId . '_' . implode('_', $offerIds);
					
					if (stripos($errorMsg, 'дубликат') !== false || 
						stripos($errorMsg, 'duplicate') !== false ||
						stripos($errorMsg, 'already exists') !== false ||
						stripos($errorMsg, 'unique') !== false ||
						stripos($errorMsg, 'уникаль') !== false) {
						
						Logger::info('Заказ поставщику уже существует в 1С (дубликат)', [
							'deal_id' => $dealId,
							'partner_name' => $partnerName,
							'partner_id' => $partnerId,
							'offer_ids' => $offerIds,
							'1c_error' => $errorMsg
						]);
						
						$createdOrders[] = [
							'partner_id' => $partnerId,
							'partner_name' => $partnerName,
							'supplier_order_number' => 'ALREADY_EXISTS',
							'product_count' => count($groupProducts),
							'status' => 'duplicate'
						];
						$duplicateCount++;
						continue;
					}
					
					// Все остальные ошибки — реальные
					throw new \Exception($errorMsg);
				}
				
				$supplierOrderNumber = $result['document_number'] ?? '';
				$supplierOrderUid = $result['document_uid'] ?? '';
				
				$createdOrders[] = [
					'partner_id' => $partnerId,
					'partner_name' => $partnerName,
					'supplier_order_number' => $supplierOrderNumber,
					'supplier_order_uid' => $supplierOrderUid,
					'product_count' => count($groupProducts),
					'product_names' => $supplierOrderData['product_names'] ?? [],
					'status' => 'created'
				];
				
				Logger::info('Заказ поставщику создан', [
					'deal_id' => $dealId,
					'partner_name' => $partnerName,
					'partner_id' => $partnerId,
					'supplier_order' => $supplierOrderNumber,
					'items_count' => count($groupProducts),
					'products' => implode(', ', array_column($groupProducts, 'PRODUCT_NAME'))
				]);
				
			} catch (\Exception $e) {
				Logger::error('Ошибка создания заказа поставщику', [
					'deal_id' => $dealId,
					'partner_id' => $group['partner_id'],
					'partner_name' => $group['partner_name'],
					'error' => $e->getMessage()
				]);
				// Продолжаем обработку остальных поставщиков
			}
			
			// Пауза между запросами к 1С
			usleep(200000);
		}
        
		// Шаг 4: Обновление полей сделки (только реально созданные заказы)
		$successfulOrders = [];
		if (!empty($createdOrders)) {
			// Берём только реально созданные заказы
			$newOrderNumbers = array_filter(
				array_column($createdOrders, 'supplier_order_number'),
				fn($n) => $n && $n !== 'ALREADY_EXISTS'
			);
			
			if (!empty($newOrderNumbers)) {
				// Объединяем с уже существующими
				$allOrderNumbers = [];
				if (!empty($existingSupplierOrders) && $existingSupplierOrders !== '0') {
					$allOrderNumbers = array_filter(explode(',', $existingSupplierOrders));
				}
				$allOrderNumbers = array_merge($allOrderNumbers, $newOrderNumbers);
				$allOrderNumbers = array_unique($allOrderNumbers);
				
				$updateFields = [
					$config['deal_fields']['UF_CRM_1C_SUPPLIER_ORDER_ID'] => implode(',', $allOrderNumbers),
					$config['deal_fields']['UF_CRM_SUPPLIER_ORDER_DATE'] => formatDate1C(date('c')),
				];
				
				$updated = updateDealViaRest($dealId, $updateFields, $config);
				if (!$updated) {
					Logger::warning('Не удалось обновить сделку', ['deal_id' => $dealId]);
				}
			}
			
			// Шаг 5: Запись в таймлайн
			$successfulOrders = array_filter($createdOrders, fn($o) => $o['supplier_order_number'] !== 'ALREADY_EXISTS');
			
			if (!empty($successfulOrders)) {
				$orderList = implode('; ', array_map(function($o) {
					$productsShort = count($o['product_names']) > 2 
						? $o['product_names'][0] . ', ... (+' . (count($o['product_names'])-1) . ')' 
						: implode(', ', $o['product_names']);
					return "{$o['partner_name']} ({$o['partner_id']}) → №{$o['supplier_order_number']}: {$productsShort}";
				}, $successfulOrders));
				
				$comment = "Заказы поставщику: создано=" . count($successfulOrders);
				if ($duplicateCount > 0) $comment .= ", дубликаты={$duplicateCount}";
				$comment .= " | {$orderList}";
				
				createTimelineEntry($dealId, $comment, $config);
			} elseif ($duplicateCount > 0) {
				$comment = "Заказы поставщику: все поставщики уже обработаны (дубликаты={$duplicateCount})";
				createTimelineEntry($dealId, $comment, $config);
			}
		}

		$createdCount += count($successfulOrders);

        $processedCount++;
        
    } catch (\Exception $e) {
        $failedCount++;
        $errorMessage = $e->getMessage();
        $failures[] = [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ];
        
        Logger::error('Критическая ошибка обработки сделки', [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ]);
    }
    
    // Пауза между сделками
    usleep(200000); // 200 мс
}

// === ИТОГИ ===
Logger::info('=== ЗАВЕРШЕНИЕ ШАГА 2.1 ===', [
    'processed' => $processedCount,
    'created' => $createdCount,
    'failed' => $failedCount,
    'total' => count($dealsToProcess)
]);

// Вывод результата
$result = [
    'success' => $failedCount === 0,
    'processed' => $processedCount,
    'created' => $createdCount,
    'failed' => $failedCount,
    'total' => count($dealsToProcess),
    'failures' => $failures,
    'timestamp' => date('Y-m-d H:i:s')
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

// ===== ФУНКЦИИ =====

/**
 * Получение списка сделок для создания заказов поставщику
 * ФИКС: корректная работа с CATEGORY_ID и UF-полями
 */
function getDealsForSupplierOrder($config) {
    $deals = [];
    $customerOrderField = $config['deal_fields']['UF_CRM_1C_ORDER_ID'];
    $supplierOrderField = $config['deal_fields']['UF_CRM_1C_SUPPLIER_ORDER_ID'];
    $organizationField = 'UF_CRM_1773266651';
    
    // Явный список полей: системные + пользовательские
    $selectFields = [
        'ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID',
        $customerOrderField, $supplierOrderField, $organizationField,
        'COMPANY_ID', 'CONTACT_ID', 'COMPANY_INN', 'COMPANY_KPP'
    ];
    
    // === Запрос 1: Розница ===
    $filterRetail = [
        'STAGE_ID' => $config['trigger_stages']['retail'],
        "!$customerOrderField" => false,
        $supplierOrderField => false
    ];
    
    $dbRetail = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filterRetail,
        $selectFields,
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );

    while ($deal = $dbRetail->Fetch()) {
        // 🔹 ФИКС: если CATEGORY_ID пустой — догружаем через GetById
        if (empty($deal['CATEGORY_ID'])) {
            $fullDeal = \CCrmDeal::GetById($deal['ID']);
            if (is_object($fullDeal) && method_exists($fullDeal, 'Fetch')) {
                $fullDeal = $fullDeal->Fetch();
            }
            if (is_array($fullDeal) && isset($fullDeal['CATEGORY_ID'])) {
                $deal['CATEGORY_ID'] = $fullDeal['CATEGORY_ID'];
            }
        }
        
        // 🔹 ФИКС: пустая категория = розница (фоллбэк)
        $dealCategory = (string)($deal['CATEGORY_ID'] ?? '');
        $retailCat = (string)($config['deal_categories']['retail'] ?? '0');
        $isRetail = ($dealCategory === $retailCat || $dealCategory === '');
        
        if ($isRetail) {
            $deals[] = $deal;
        }
    }
    
    // === Запрос 2: Опт ===
    $filterWholesale = [
        'STAGE_ID' => $config['trigger_stages']['wholesale'],
        "!$customerOrderField" => false,
        $supplierOrderField => false
    ];
    
    $dbWholesale = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filterWholesale,
        $selectFields,
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );
    
    while ($deal = $dbWholesale->Fetch()) {
        // 🔹 Аналогичный фикс для категории
        if (empty($deal['CATEGORY_ID'])) {
            $fullDeal = \CCrmDeal::GetById($deal['ID']);
            if (is_object($fullDeal) && method_exists($fullDeal, 'Fetch')) {
                $fullDeal = $fullDeal->Fetch();
            }
            if (is_array($fullDeal) && isset($fullDeal['CATEGORY_ID'])) {
                $deal['CATEGORY_ID'] = $fullDeal['CATEGORY_ID'];
            }
        }
        
        $dealCategory = (string)($deal['CATEGORY_ID'] ?? '');
        $wholesaleCat = (string)($config['deal_categories']['wholesale'] ?? '1');
        $isWholesale = ($dealCategory === $wholesaleCat);
        
        if ($isWholesale) {
            $deals[] = $deal;
        }
    }
    
    return $deals;
}

/**
 * Получение УИД заказа покупателя из 1С
 */
function getCustomerOrderUidFrom1C($oneC, $dealId, $orderNumber, $config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
            $config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/json');
        
        $url = rtrim($config['1c_base_url'], '/') . '/CustomerOrder';
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() !== 200) {
            return false;
        }
        
        $orders = json_decode($response, true);
        if (!is_array($orders)) {
            return false;
        }
        
        // Поиск по номеру + привязке к сделке
        foreach ($orders as $order) {
            if (($order['Номер'] ?? '') === $orderNumber &&
                (string)($order['DNK_id'] ?? '') === (string)$dealId &&
                ($order['Проведен'] ?? '') === 'Да') {
                
                // 🔹 Приоритет: ИДЗаписи > УИД
                $identifier = $order['ИДЗаписи'] ?? $order['УИД'] ?? '';
                if (!empty($identifier)) {
                    return $identifier;
                }
            }
        }
        
        // Фоллбэк: поиск только по привязке к сделке
        foreach ($orders as $order) {
            if ((string)($order['DNK_id'] ?? '') === (string)$dealId &&
                ($order['Проведен'] ?? '') === 'Да') {
                $identifier = $order['ИДЗаписи'] ?? $order['УИД'] ?? '';
                if (!empty($identifier)) {
                    return $identifier;
                }
            }
        }
        
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Ошибка получения УИД заказа покупателя', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение кода номенклатуры 1С из свойства товара
 */
function getProductCode1C($productId) {
    if (!$productId) return '';
    
    $product = \CCrmProduct::GetById($productId);
    if (!$product || !is_array($product)) return '';
    
    $iblockId = $product['IBLOCK_ID'] ?? 14;
    $propertyId = 1085;  // ← Свойство "Код номенклатуры 1С"
    
    // 🔹 Основной способ: через CIBlockElement::GetProperty
    if (class_exists('\CIBlockElement') && \Bitrix\Main\Loader::includeModule('iblock')) {
        $dbProp = \CIBlockElement::GetProperty(
            $iblockId,
            $productId,
            ['sort' => 'asc'],
            ['ID' => $propertyId]  // ← Фильтр по ID свойства!
        );
        $prop = $dbProp->Fetch();
        
        if ($prop && !empty($prop['VALUE'])) {
            return trim((string)$prop['VALUE']);  // Например: "НФ-00086500"
        }
    }
    
    // 🔹 Fallback: XML_ID
    if (!empty($product['XML_ID'])) {
        return (string)$product['XML_ID'];
    }
    
    // 🔹 Fallback: сам ID (чтобы не сломать интеграцию)
    Logger::warning('Не найден код 1С, используем ID товара', [
        'product_id' => $productId
    ]);
    return (string)$productId;
}

/**
 * Форматирование даты из формата 1С в формат Битрикс24
 */
function formatDate1C($date) {
    if (empty($date)) return '';
    return preg_replace('/\s.*$/', '', trim($date));
}

/**
 * Создание записи в таймлайне
 */
function createTimelineEntry($dealId, $comment, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.add.json';
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment
            ]
        ];
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && !empty($result['result'])) {
            Logger::debug('Запись добавлена в таймлайн', [
                'deal_id' => $dealId,
                'timeline_id' => $result['result']
            ]);
            return $result['result'];
        }
        
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Ошибка создания записи в таймлайне', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Обновление полей сделки через REST API
 */
function updateDealViaRest($dealId, $fields, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.update.json';
        $postData = http_build_query([
            'id' => (int)$dealId,
            'fields' => $fields
        ]);
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        return ($httpCode === 200 && !empty($result['result']));
        
    } catch (\Exception $e) {
        Logger::error('Ошибка обновления сделки через REST', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение товаров сделки
 */
function getDealProducts($dealId) {
    $products = [];
    
    $dbResult = \CCrmProductRow::GetList(
        [],
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => $dealId],
        false, false, []
    );
    
    while ($row = $dbResult->Fetch()) {
        $products[] = [
            'PRODUCT_ID' => $row['PRODUCT_ID'] ?? '',
            'PRODUCT_NAME' => $row['PRODUCT_NAME'] ?? $row['NAME'] ?? '',
            'QUANTITY' => $row['QUANTITY'] ?? 1,
            'PRICE' => $row['PRICE'] ?? 0,
            'DISCOUNT_PRICE' => $row['DISCOUNT_PRICE'] ?? $row['PRICE'],
            'CURRENCY_ID' => $row['CURRENCY_ID'] ?? 'RUB',
        ];
    }
    
    return $products;
}

/**
 * Получение УИД поставщика из свойства товара (PROPERTY_1212)
 */
function getSupplierUidFromProduct($productId) {
    if (!$productId) return '';
    
    if (!\Bitrix\Main\Loader::includeModule('iblock')) {
        return '';
    }
    
    $product = \CCrmProduct::GetById($productId);
    if (!$product || !is_array($product)) return '';
    
    $iblockId = $product['IBLOCK_ID'] ?? 14;
    $propertyId = 1212; // PROPERTY_1212 = УИД поставщика 1С
    
    $dbProp = \CIBlockElement::GetProperty(
        $iblockId,
        $productId,
        ['sort' => 'asc'],
        ['ID' => $propertyId]
    );
    $prop = $dbProp->Fetch();
    
    return $prop && !empty($prop['VALUE']) ? trim($prop['VALUE']) : '';
}

/**
 * Поиск компании в Б24 по полю UF_CRM_UID
 */
function findCompanyBySupplierUid($supplierUid, $config) {
    static $cache = [];  // 👈 Кэш в рамках одного запуска скрипта
    
    if (empty($supplierUid)) return null;
    
    // 👈 Возвращаем из кэша ТОЛЬКО если там уже есть обработанный результат
    if (isset($cache[$supplierUid])) {
        return $cache[$supplierUid];
    }
    
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.company.list.json';
        $fields = [
            'filter' => ['UF_CRM_UID' => $supplierUid],
            'select' => ['ID', 'TITLE', 'UF_CRM_UID'],
            'start' => 0
        ];
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);

        $company = null;
        if ($httpCode === 200 && !empty($result['result'][0])) {
            $company = [
                'id' => $result['result'][0]['ID'],
                'title' => $result['result'][0]['TITLE'] ?? ''
            ];
        }

        $cache[$supplierUid] = $company;
        return $company;
        
    } catch (\Exception $e) {
        Logger::error('Ошибка поиска компании по УИД поставщика', [
            'supplier_uid' => $supplierUid,
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

/**
 * Вынесено: получение УИД организации из сделки
 */
function getOrganizationUuidFromDeal($deal, $config) {
    $organizationUuid = '';
    $organizationFieldCode = 'UF_CRM_1773266651';
    $rawValue = $deal[$organizationFieldCode] ?? null;
    $organizationElementId = null;
    
    if ($rawValue !== null && $rawValue !== '') {
        if (is_array($rawValue)) {
            $val = $rawValue['VALUE'] ?? null;
            if (is_array($val)) $val = reset($val);
            if ($val !== null) $organizationElementId = (int)$val;
        } else {
            $organizationElementId = (int)$rawValue;
        }
    }
    
    if ($organizationElementId && \Bitrix\Main\Loader::includeModule('iblock')) {
        $iblockId = 59;
        $propertyId = 1096;
        
        $dbProp = \CIBlockElement::GetProperty($iblockId, $organizationElementId, ['sort' => 'asc'], ['ID' => $propertyId]);
        $prop = $dbProp->Fetch();
        
        if ($prop && !empty($prop['VALUE'])) {
            $organizationUuid = trim((string)$prop['VALUE']);
        }
    }
    
    if (empty($organizationUuid)) {
        $category = isset($deal['CATEGORY_ID']) ? (string)$deal['CATEGORY_ID'] : '';
        $retailCat = (string)($config['deal_categories']['retail'] ?? '0');
        $wholesaleCat = (string)($config['deal_categories']['wholesale'] ?? '1');
        
        if ($category === $wholesaleCat) {
            $organizationUuid = $config['default_organization_uid']['wholesale']
                ?? $config['default_organization_uid']['retail'] ?? '';
        } else {
            $organizationUuid = $config['default_organization_uid']['retail']
                ?? $config['default_organization_uid']['wholesale'] ?? '';
        }
    }
    
    return $organizationUuid;
}

/**
 * Подготовка данных для заказа поставщику (НЕСКОЛЬКО товаров одного поставщика)
 */
function prepareSupplierOrderDataMultipleItems($deal, $products, $customerOrderUid, $partnerId, $config) {
    // 🔹 Получение УИД организации
    $organizationUuid = getOrganizationUuidFromDeal($deal, $config);
    
    // 🔹 Формирование дат
    $dateObj = new \DateTime();
    $dateObj->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $isoDate = $dateObj->format(\DateTime::ATOM);
    $receiptDate = $dateObj->format(\DateTime::ATOM);
    
    // 🔹 Подготовка товаров (массив)
    $items = [];
    $key = 1;
    foreach ($products as $item) {
        $productId = $item['PRODUCT_ID'] ?? 0;
        $offerId = getProductCode1C($productId);
        
        if (empty($offerId)) {
            Logger::warning('Товар пропущен: не найден код номенклатуры 1С', [
                'product_id' => $productId,
                'product_name' => $item['PRODUCT_NAME'] ?? ''
            ]);
            continue;
        }
        
        $basePrice = (float)($item['PRICE'] ?? 0);
        $finalPrice = (float)($item['DISCOUNT_PRICE'] ?? $item['PRICE'] ?? 0);
        $discount = $basePrice - $finalPrice;
        $discountPercent = $basePrice > 0 ? round(($discount / $basePrice * 100), 2) : 0;
        
        $items[] = [
            'key' => $key++,
            'offer_Id' => $offerId,
            'lot' => 'f819f5f2-1cdd-11ea-8116-0050569b6607',
            'quantity' => (float)($item['QUANTITY'] ?? 1),
            'basePrice' => round($basePrice, 2),
            'finalPrice' => round($finalPrice, 2),
            'discountsPercent' => $discountPercent,
            'discountsPrice' => round($discount, 2)
        ];
    }
    
    if (empty($items)) {
        return null; // Нет валидных товаров для заказа
    }
    
    // Формирование итогового массива
    $productNames = array_column($products, 'PRODUCT_NAME');
    
    return [
        'id' => (string)$deal['ID'],
        'id_custumerOrder' => (string)$deal['ID'],
        'date' => $isoDate,
        'receipt_date' => $receiptDate,
        'inn' => $deal['COMPANY_INN'] ?? '',
        'kpp' => $deal['COMPANY_KPP'] ?? '',
        'id_partner' => (string)($partnerId ?? ''),
        'payName' => '',
        'type' => '',
        'status' => '',
        'IncNumber' => '',
        'IncDate' => '',
        'NDS' => '1',
        'organization' => $organizationUuid,
        'structure' => $organizationUuid,
        'store' => '',
        'items' => $items,
        'product_names' => $productNames
    ];
}
?>