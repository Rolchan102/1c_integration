<?php
/**
 * Шаг 1: Триггер стадии → Заказ покупателя
 * Создаёт заказ покупателя в 1С при переходе сделки на стадию "В обработке"
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

// Ручная проверка авторизации (без блокировки сессии)
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

Logger::info('=== ЗАПУСК ШАГА 2: Создание заказов покупателя в 1С ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : $USER->GetID()
]);

// === ПОЛУЧЕНИЕ СПИСКА СДЕЛОК ДЛЯ ОБРАБОТКИ ===
$dealsToProcess = getDealsForProcessing($config);

if (empty($dealsToProcess)) {
    Logger::info('Нет сделок для обработки');
    echo json_encode(
        ['success' => true, 'processed' => 0, 'message' => 'Нет сделок для обработки'], 
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

Logger::info('Найдено сделок для обработки', ['count' => count($dealsToProcess)]);

// === ОБРАБОТКА КАЖДОЙ СДЕЛКИ ===
$processedCount = 0;
$failedCount = 0;
$failures = [];

foreach ($dealsToProcess as $deal) {
    try {
        Logger::info('Обработка сделки', [
            'deal_id' => $deal['ID'], 
            'title' => $deal['TITLE'], 
            'category' => $deal['CATEGORY_ID']
        ]);
        
        // ШАГ 0: Проверяем, есть ли уже заказ в 1С для этой сделки
        Logger::debug('Проверка наличия заказа в 1С', ['deal_id' => $deal['ID']]);
        $existingOrder = $oneC->getOrderByDnkId($deal['ID']);
        
		if ($existingOrder && !empty($existingOrder['Номер'])) {
			// Заказ уже есть — просто обновляем поля сделки
			Logger::info('Заказ уже существует в 1С, обновляем данные', [
				'deal_id' => $deal['ID'],
				'order_number' => $existingOrder['Номер']
			]);
			
			// 1. Данные заказа из 1С
			$orderNumber = $existingOrder['Номер'];
			$orderDate1C = $existingOrder['Дата'] ?? '';
			
			// 2. Данные счета: пробуем взять из 1С, иначе — из сделки Б24
			$invoiceNumber = !empty($existingOrder['НомерСчетаНаОплату']) 
				? $existingOrder['НомерСчетаНаОплату'] 
				: ($deal[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']] ?? '');

			$invoiceDate1C = !empty($existingOrder['ДатаСчетаНаОплату']) 
				? $existingOrder['ДатаСчетаНаОплату'] 
				: ($deal[$config['deal_fields']['UF_CRM_INVOICE_DATE']] ?? '');
			
			$invoiceUid = !empty($existingOrder['УИДСчетаНаОплату']) 
				? $existingOrder['УИДСчетаНаОплату'] 
				: '';
			
			// 3. Если УИД всё ещё пуст, но есть номер счета — попробуем найти его
			if (empty($invoiceUid) && !empty($invoiceNumber)) {
				$functionsFile = $_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/functions_1c_lookup.php';
				if (file_exists($functionsFile)) {
					require_once($functionsFile);
					$foundUid = \Integration\getInvoiceUidFrom1C($deal['ID'], $invoiceNumber, $config);
					if (!empty($foundUid)) {
						$invoiceUid = $foundUid;
					}
				}
			}
            
            $orderResult = [
				'success' => true,
				'order_number' => $orderNumber,
				'order_date' => $orderDate1C,
				'invoice_number' => $invoiceNumber,
				'invoice_date' => $invoiceDate1C,
				'invoice_uid' => $invoiceUid,
				'is_posted' => ($existingOrder['Проведен'] ?? 'Нет') === 'Да',
				'is_paid' => ($existingOrder['Оплачен'] ?? 'Нет') === 'Да'
			];
            
        } else {
            // Заказа нет — создаём новый
			Logger::info('Заказ не найден, создаём новый в 1С', ['deal_id' => $deal['ID']]);

			// Шаг 1.5: Получаем полные данные счета из 1С для привязки к заказу
			$invoiceData = null;
			$invoiceNumberFromDeal = $deal[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']] ?? '';
			
			if (!empty($invoiceNumberFromDeal)) {
				Logger::debug('Попытка найти данные счета в 1С для привязки к заказу', [
					'deal_id' => $deal['ID'],
					'invoice_number' => $invoiceNumberFromDeal
				]);
				
				$functionsFile = $_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/functions_1c_lookup.php';
				if (file_exists($functionsFile)) {
					require_once($functionsFile);
				}
				
				// Получаем и UID, и Номер счета из 1С
				$invoiceData = getInvoiceDataFrom1C(
					$deal['ID'], 
					$invoiceNumberFromDeal, 
					$config
				);
				
				if ($invoiceData) {
					Logger::info('Данные счета найдены в 1С, будет передан guid_order', [
						'deal_id' => $deal['ID'],
						'guid_order' => $invoiceData['uid']
					]);
				}
			}
			
			// Шаг 1: Получение товаров сделки
			$products = getDealProducts($deal['ID']);
			if (empty($products)) {
				throw new \Exception('В сделке отсутствуют товары. Заказ в 1С не может быть создан без товаров.');
			}
			$deal['PRODUCT_ROWS'] = $products;
			
			// Шаг 2: Создание заказа в 1С — передаем и UID, и Номер счета
			$additionalOrderParams = [];
			if ($invoiceData && !empty($invoiceData['uid'])) {
				$additionalOrderParams = [
					'guid_order' => $invoiceData['uid'],
				];
			}
			
			$orderResult = $oneC->createCustomerOrder($deal, $additionalOrderParams);
		}

		// ШАГ 3: Преобразование даты из формата 1С в формат Б24
		try {
			// Сначала пробуем дату счета
			$dateRaw = !empty($orderResult['invoice_date']) 
				? $orderResult['invoice_date'] 
				: (!empty($orderResult['order_date']) ? $orderResult['order_date'] : '');
			
			if (!empty($dateRaw)) {
				// Пробуем распарсить формат 1С: "05.03.2026 0:00:00"
				$dt = \DateTime::createFromFormat('d.m.Y H:i:s', trim($dateRaw));
				if ($dt) {
					$dateForB24 = $dt->format('Y-m-d');
				} else {
					// Фоллбэк: пробуем общий парсер
					$dt = new \DateTime($dateRaw);
					$dateForB24 = $dt->format('Y-m-d');
				}
				$invoiceDateDisplay = preg_replace('/\s.*$/', '', trim($dateRaw));
			} else {
				// Фоллбэк: используем дату создания сделки, а не "сегодня"
				$dealDate = $deal['DATE_CREATE'] ?? date('Y-m-d');
				$dt = new \DateTime($dealDate);
				$dateForB24 = $dt->format('Y-m-d');
				$invoiceDateDisplay = $dt->format('d.m.Y');
			}
		} catch (\Exception $e) {
			Logger::error('Ошибка конвертации даты', [
				'original' => $orderResult['invoice_date'] ?? $orderResult['order_date'] ?? 'EMPTY',
				'error' => $e->getMessage()
			]);
			$dateForB24 = date('Y-m-d');
			$invoiceDateDisplay = date('d.m.Y');
		}

		// ШАГ 4: Обновление полей сделки — БЕЗОПАСНАЯ ВЕРСИЯ
		$updateFields = [];
		
		// Обновляем номер заказа ТОЛЬКО если он вернулся из 1С
		if (!empty($orderResult['order_number'])) {
			$updateFields[$config['deal_fields']['UF_CRM_1C_ORDER_ID']] = $orderResult['order_number'];
		} else {
			Logger::warning('1С не вернула номер заказа', [
				'deal_id' => $deal['ID'],
				'success' => $orderResult['success'] ?? null,
				'order_number' => $orderResult['order_number'] ?? null,
				'invoice_number' => $orderResult['invoice_number'] ?? null
			]);
		}

		// Обновляем номер счёта ТОЛЬКО если он вернулся из 1С И не пустой
		if (!empty($orderResult['invoice_number'])) {
			$updateFields[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']] = $orderResult['invoice_number'];
		}
		// Если invoice_number пустой — просто не трогаем поле, сохраняем старое значение
		
		// Дату обновляем всегда (она есть даже при пустом номере)
		$updateFields[$config['deal_fields']['UF_CRM_1772716650612']] = $dateForB24;
		
		// Для розницы: устанавливаем "Оплата получена = Нет"
		if ($deal['CATEGORY_ID'] == $config['deal_categories']['retail']) {
			$updateFields[$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED']] = '261';
		}
		
		// Обновляем сделку ТОЛЬКО если есть что обновлять
		if (!empty($updateFields)) {
			$updated = updateDealViaRest($deal['ID'], $updateFields, $config);
			if (!$updated) {
				throw new \Exception('Ошибка обновления сделки через REST API');
			}
		}
        
        // ШАГ 5: Загрузка счета в таймлайн (если есть УИД)
        if (!empty($orderResult['invoice_number']) && !empty($orderResult['invoice_uid'])) {
            $pdfContent = $oneC->getInvoicePdf($deal['ID']);
            if ($pdfContent) {
                uploadInvoiceToTimeline($deal['ID'], $orderResult['invoice_number'], $pdfContent, $config);
            } else {
                Logger::warning('PDF счета не получен, но заказ создан/найден успешно', [
                    'deal_id' => $deal['ID'],
                    'invoice_number' => $orderResult['invoice_number']
                ]);
            }
        }
        
        $processedCount++;
        Logger::info('Сделка успешно обработана', [
            'deal_id' => $deal['ID'],
            'order_number' => $orderResult['order_number'],
            'invoice_number' => $orderResult['invoice_number'],
            'invoice_date' => $invoiceDateDisplay,
            'action' => $existingOrder ? 'existing_order_found' : 'new_order_created'
        ]);
        
    } catch (\Exception $e) {
        $failedCount++;
        $errorMessage = $e->getMessage();
        $failures[] = [
            'deal_id' => $deal['ID'],
            'error' => $errorMessage
        ];
        
        Logger::error('Ошибка обработки сделки', [
            'deal_id' => $deal['ID'],
            'error' => $errorMessage
        ]);
    }
    
    // Пауза между запросами к 1С
    usleep(200000); // 200 мс
}

// === ИТОГИ ===
Logger::info('=== ЗАВЕРШЕНИЕ ШАГА 2 ===', [
    'processed' => $processedCount,
    'failed' => $failedCount,
    'total' => count($dealsToProcess),
    // Отладочная информация (опционально)
    'debug' => [
        'base_url' => $config['1c_base_url'] ?? 'NOT_SET',
        'webhook_url' => $config['b24_webhook_url'] ?? 'NOT_SET',
    ]
]);

// Вывод результата
$result = [
    'success' => $failedCount === 0,
    'processed' => $processedCount,
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

/**
 * Получение списка сделок для обработки
 */
function getDealsForProcessing($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_1C_ORDER_ID'];
    
    // Простой и надёжный подход: два отдельных запроса для розницы и опта
    // Это обходит проблемы со сложными фильтрами LOGIC + UF-поля в старом API
    
    $commonFilter = [
        $orderField => false, // Пустое поле = сделка ещё не обработана
    ];
    
    // === Запрос 1: Розница ===
    $filterRetail = array_merge($commonFilter, [
        'CATEGORY_ID' => $config['deal_categories']['retail'],
        'STAGE_ID' => $config['trigger_stages']['retail'],
    ]);
    
    $dbRetail = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],   // $order
        $filterRetail,               // $filter
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UF_*'], // $select
        false,                       // $group
        ['nTopCount' => $config['batch_size'] ?? 20] // $navStartParams
        // Всего 5 параметров — как требует API
    );
    
    while ($deal = $dbRetail->Fetch()) {
        $deals[] = $deal;
    }
    
    // === Запрос 2: Опт (если стадии отличаются) ===
    if ($config['trigger_stages']['retail'] !== $config['trigger_stages']['wholesale']) {
        $filterWholesale = array_merge($commonFilter, [
            'CATEGORY_ID' => $config['deal_categories']['wholesale'],
            'STAGE_ID' => $config['trigger_stages']['wholesale'],
        ]);
        
        $dbWholesale = \CCrmDeal::GetList(
            ['DATE_MODIFY' => 'ASC'],
            $filterWholesale,
            ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UF_*'],
            false,
            ['nTopCount' => $config['batch_size'] ?? 20]
        );
        
        while ($deal = $dbWholesale->Fetch()) {
            $deals[] = $deal;
        }
    }
    
    return $deals;
}

/**
 * Получение товаров сделки — ИСПРАВЛЕННАЯ ВЕРСИЯ
 */
function getDealProducts($dealId) {
    $products = [];
    
    // Правильный способ: CCrmProductRow::GetList с фильтром по владельцу
    $dbResult = \CCrmProductRow::GetList(
        [], // $order
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => $dealId], // $filter: 'D' = Deal
        false, // $select
        false, // $group
        [] // $navParams
    );
    
    while ($row = $dbResult->Fetch()) {
        $products[] = [
            'PRODUCT_ID' => $row['PRODUCT_ID'] ?? '',
            'PRODUCT_NAME' => $row['PRODUCT_NAME'] ?? $row['NAME'] ?? '',
            'QUANTITY' => $row['QUANTITY'] ?? 1,
            'PRICE' => $row['PRICE'] ?? 0,
            'DISCOUNT_PRICE' => $row['DISCOUNT_PRICE'] ?? $row['PRICE'],
            'CURRENCY_ID' => $row['CURRENCY_ID'] ?? 'RUB',
            'ONE_C_UID' => getProduct1CCode($row['PRODUCT_ID'] ?? 0),
        ];
    }
    
    return $products;
}

/**
 * Загрузка счета в таймлайн сделки через crm.timeline.comment.add
 * @param int $dealId ID сделки
 * @param string $invoiceNumber Номер счета
 * @param string $pdfContent Бинарные данные PDF
 * @param array $config Конфигурация интеграции
 */
function uploadInvoiceToTimeline($dealId, $invoiceNumber, $pdfContent, $config) {
    try {
        // 1. Кодируем PDF в base64
        $base64Pdf = base64_encode($pdfContent);
        
        // 2. Формируем payload для crm.timeline.comment.add
        // ВАЖНО: fields в нижнем регистре (требование с v23.100.0)
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',  // Тип сущности: deal, lead, contact, company
                'COMMENT' => "Счет на оплату №{$invoiceNumber} от " . date('d.m.Y'),
                'FILES' => [
                    // Формат для crm.timeline.comment.add: [имя_файла, base64_контент]
                    ["Счет_{$invoiceNumber}.pdf", $base64Pdf]
                ]
            ]
        ];
        
        // 3. Отправляем запрос через webhook
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.add.json';
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        
        // 4. Обработка ответа
        if ($httpCode !== 200) {
            throw new \Exception("HTTP {$httpCode}: {$curlError}");
        }
        
        $result = json_decode($response, true);
        
        if (empty($result['result'])) {
            $errorMsg = $result['error_description'] ?? $result['error'] ?? 'unknown error';
            throw new \Exception("API error: {$errorMsg}");
        }
        
        Logger::info('Счет успешно добавлен в таймлайн', [
            'deal_id' => $dealId,
            'invoice_number' => $invoiceNumber,
            'timeline_id' => $result['result']
        ]);
        
        return true;

    } catch (\Exception $e) {
        Logger::error('Ошибка загрузки счета в таймлайн', [
            'deal_id' => $dealId,
            'invoice_number' => $invoiceNumber,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Не прерываем основной процесс — загрузка файла не критична
        return false;
    }
}

/**
 * Обновление полей сделки через REST API
 * @param int $dealId ID сделки
 * @param array $fields Поля для обновления
 * @param array $config Конфигурация
 * @return bool true при успехе
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
        \Integration\Logger::error('Ошибка обновления сделки через REST', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение данных счета на оплату из 1С по номеру и DNK_id
 * 
 * @param string $dealId ID сделки (DNK_id в 1С)
 * @param string|null $invoiceNumber Опциональный номер счета для фильтрации
 * @param array $config Конфигурация
 * @return array|false Массив с данными счета или false при ошибке
 */
function getInvoiceDataFrom1C($dealId, $invoiceNumber = '', $config = []) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $login = $config['1c_auth']['login'] ?? '';
        $password = $config['1c_auth']['password'] ?? '';
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding("{$login}:{$password}", 'UTF-8', 'UTF-8')
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/json');
        
        $baseUrl = rtrim($config['1c_base_url'], '/');
        $url = "{$baseUrl}/orders/0/{$dealId}";
        
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();

        if ($statusCode !== 200) {
            Logger::debug('Ошибка получения счетов из 1С', [
                'deal_id' => $dealId,
                'status' => $statusCode
            ]);
            return false;
        }
        
        $invoices = @json_decode($response, true);
        if (!is_array($invoices)) {
            return false;
        }
        
        // Если передан номер счета — ищем точное совпадение
        if (!empty($invoiceNumber)) {
            $normalize = function($s) {
                return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', trim($s)));
            };
            $expectedNorm = $normalize($invoiceNumber);
            
            foreach ($invoices as $inv) {
                $invNum = $inv['Номер'] ?? $inv['НомерСчета'] ?? '';
                if ($normalize($invNum) === $expectedNorm) {
                    return [
                        'uid' => $inv['УИД'] ?? $inv['УИДСчета'] ?? '',
                        'number' => $inv['Номер'] ?? $inv['НомерСчета'] ?? '',
                        'date' => $inv['Дата'] ?? $inv['ДатаСчета'] ?? '',
                        'posted' => ($inv['Проведен'] ?? '') === 'Да'
                    ];
                }
            }
        }
        
        // Если номер не передан или не найден — возвращаем первый непустой счет
        foreach ($invoices as $inv) {
            $invNum = $inv['Номер'] ?? $inv['НомерСчета'] ?? '';
            $invUid = $inv['УИД'] ?? $inv['УИДСчета'] ?? '';
            if (!empty($invNum) && !empty($invUid)) {
                return [
                    'uid' => $invUid,
                    'number' => $invNum,
                    'date' => $inv['Дата'] ?? $inv['ДатаСчета'] ?? '',
                    'posted' => ($inv['Проведен'] ?? '') === 'Да'
                ];
            }
        }
        
        Logger::debug('Счет не найден в 1С', [
            'deal_id' => $dealId,
            'invoice_number' => $invoiceNumber
        ]);
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Исключение при поиске счета в 1С', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение кода номенклатуры 1С для товара
 */
function getProduct1CCode($productId) {
    if (!$productId) return '';
    
    $product = \CCrmProduct::GetById($productId);
    if (!$product || !is_array($product)) return '';
    
    $iblockId = $product['IBLOCK_ID'] ?? 0;
    if (!$iblockId) {
        return (string)($product['XML_ID'] ?? $productId);
    }
    
    // Свойство 1085 = "Код номенклатуры 1С" (как в вашем случае)
    $dbProp = \CIBlockElement::GetProperty(
        $iblockId,
        $productId,
        ['sort' => 'asc'],
        ['ID' => 1085]
    );
    $prop = $dbProp->Fetch();
    
    if ($prop && !empty($prop['VALUE'])) {
        return trim((string)$prop['VALUE']);
    }
    
    // Fallback
    return (string)($product['XML_ID'] ?? $productId);
}

/**
 * Форматирование даты из формата 1С в формат Битрикс24
 * "05.03.2026 0:00:00" → "05.03.2026"
 */
function formatDate1C($date) {
    if (empty($date)) return '';
    return preg_replace('/\s.*$/', '', trim($date));
}
?>