<?php
/**
 * Шаг 4: Синхронизация № заказа 1С
 * Ретросинхронизация номеров заказов и счетов из 1С для сделок с пустыми полями
 * 
 * Назначение: "подчищающий" скрипт для ситуаций когда:
 * - Сбой при первичной синхронизации
 * - Импорт данных из внешних источников
 * 
 * Направление: 1С → Битрикс24
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

Logger::info('=== ЗАПУСК ШАГА 4: Ретросинхронизация номеров заказов из 1С ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'timestamp' => date('Y-m-d H:i:s')
]);

// === ПОЛУЧЕНИЕ СПИСКА СДЕЛОК ДЛЯ РЕТРОСИНХРОНИЗАЦИИ ===
$dealsToSync = getDealsForOrderSync($config);

if (empty($dealsToSync)) {
    Logger::info('Нет сделок для ретросинхронизации номеров заказов');
    echo json_encode(['success' => true, 'checked' => 0, 'synced' => 0, 'message' => 'Нет сделок для синхронизации']);
    exit;
}

Logger::info('Найдено сделок для ретросинхронизации', ['count' => count($dealsToSync)]);

// === СИНХРОНИЗАЦИЯ НОМЕРОВ ЗАКАЗОВ ===
$checkedCount = 0;
$syncedCount = 0;
$failedCount = 0;
$syncedDeals = [];
$failures = [];

if ($dealsToSync === false) {
    Logger::error('Ошибка получения списка сделок для ретросинхронизации', [
        'error' => 'CCrmDeal::GetList returned false'
    ]);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch deals']);
    exit;
}

foreach ($dealsToSync as $deal) {
    try {
        $dealId = $deal['ID'];
        $categoryId = $deal['CATEGORY_ID'];
        $currentOrderNumber = $deal[$config['deal_fields']['UF_CRM_1C_ORDER_ID']] ?? '';
        
        Logger::debug('Ретросинхронизация заказа для сделки', [
            'deal_id' => $dealId,
            'category' => $categoryId,
            'current_order' => $currentOrderNumber ?: 'пусто'
        ]);
        
        // Шаг 1: Получение данных о заказе из 1С по ID сделки (DNK_id)
        $invoices = $oneC->getInvoices($dealId);
        
        if (empty($invoices) || !isset($invoices[0])) {
            // Заказ не найден в 1С — пропускаем (не ошибка, просто нет данных)
            Logger::debug('Заказ не найден в 1С для сделки', [
                'deal_id' => $dealId,
                'reason' => 'нет данных в 1С по данному DNK_id'
            ]);
            $checkedCount++;
            continue;
        }
        
        $checkedCount++;
        $invoice = $invoices[0]; // Берём первый (основной) счет/заказ
        
        // Шаг 2: Извлечение данных из ответа 1С
        $orderNumber = $invoice['Номер'] ?? ''; // Номер заказа покупателя
        $invoiceNumber = $invoice['НомерСчетаНаОплату'] ?? $invoice['Номер'] ?? '';
        $invoiceDateRaw = $invoice['ДатаСчетаНаОплату'] ?? $invoice['Дата'] ?? '';
        $invoiceUid = $invoice['УИДСчетаНаОплату'] ?? $invoice['УИД'] ?? '';
        $orderUid = $invoice['ЗаказПокупателяУИД'] ?? '';
        $paidStatus = ($invoice['Оплачен'] ?? 'Нет') === 'Да';
        
        // Преобразование даты в формат Б24
        $invoiceDate = !empty($invoiceDateRaw) 
            ? preg_replace('/\s.*$/', '', $invoiceDateRaw) 
            : date('d.m.Y');
        
        Logger::debug('Данные заказа получены из 1С', [
            'deal_id' => $dealId,
            'order_number' => $orderNumber,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'paid' => $paidStatus ? 'Да' : 'Нет'
        ]);
        
        // Шаг 3: Определение необходимости обновления
        $needsUpdate = false;
        $updateFields = [];
        
        // Сценарий 1: Поле заказа пустое — заполняем все поля
        if (empty($currentOrderNumber) || $currentOrderNumber === '0') {
            $needsUpdate = true;
            $updateFields = [
                $config['deal_fields']['UF_CRM_1C_ORDER_ID'] => $orderNumber,
                $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] => $invoiceNumber,
                $config['deal_fields']['UF_CRM_INVOICE_DATE'] => $invoiceDate,
            ];
            
            // Для розничного отдела: устанавливаем статус оплаты
			$paidValue = $config['payment_received']['values']['yes'];   // '260'
			$unpaidValue = $config['payment_received']['values']['no'];  // '261'
            if ($categoryId == $config['deal_categories']['retail']) {
                $updateFields[$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED']] = $paidStatus ? $paidValue : $unpaidValue;
                if ($paidStatus && !empty($invoice['Оплачено'])) {
                    $updateFields[$config['deal_fields']['UF_CRM_PAYMENT_AMOUNT']] = (float)$invoice['Оплачено'];
                }
            }
            
            // Для оптового отдела: если оплачено и стадия позволяет — меняем стадию
            if ($categoryId == $config['deal_categories']['wholesale'] && $paidStatus) {
                if (in_array($deal['STAGE_ID'], [$config['trigger_stages']['wholesale'], 'C1:1'])) {
                    $updateFields['STAGE_ID'] = 'C1:FINAL_INVOICE';
                }
            }
            
        // Сценарий 2: Поле заказа заполнено, но нет номера счета — дополняем
        } elseif (empty($deal[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']])) {
            $needsUpdate = true;
            $updateFields = [
                $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] => $invoiceNumber,
                $config['deal_fields']['UF_CRM_INVOICE_DATE'] => $invoiceDate,
            ];
        }
        
		// Шаг 4: Выполнение обновления
		if ($needsUpdate && !empty($updateFields)) {
			$updateResult = updateDealViaRest($dealId, $updateFields, $config);
			
			if ($updateResult) {
				$syncedCount++;
				$syncedDeals[] = [
					'deal_id' => $dealId,
					'order_number' => $orderNumber,
					'invoice_number' => $invoiceNumber,
					'invoice_date' => $invoiceDate,
					'paid' => $paidStatus
				];
				
				\Integration\Logger::info('Поля заказа успешно синхронизированы', [
					'deal_id' => $dealId,
					'order_number' => $orderNumber,
					'invoice_number' => $invoiceNumber,
					'fields_updated' => array_keys($updateFields)
				]);
				
				// Шаг 5: Опциональная загрузка счета в таймлайн
				if (!empty($invoiceUid) && $invoiceNumber) {
					$pdfContent = $oneC->getInvoicePdf($dealId);
					if ($pdfContent) {
						uploadInvoiceToTimeline($dealId, $invoiceNumber, $pdfContent, $config);
					}
				}
			} else {
				throw new \Exception('Ошибка обновления сделки через REST API');
			}
		} else {
            Logger::debug('Обновление не требуется для сделки', [
                'deal_id' => $dealId,
                'reason' => 'все поля уже заполнены корректно'
            ]);
        }

        // Пауза между запросами
        usleep(150000);
        
    } catch (\Exception $e) {
        $failedCount++;
        $errorMessage = $e->getMessage();
        $failures[] = [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ];
        
        Logger::error('Ошибка ретросинхронизации заказа для сделки', [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ]);
    }
}

// === ИТОГИ ===
Logger::info('=== ЗАВЕРШЕНИЕ ШАГА 4 ===', [
    'checked' => $checkedCount,
    'synced' => $syncedCount,
    'failed' => $failedCount,
    'total' => count($dealsToSync)
]);

// Вывод результата
$result = [
    'success' => $failedCount === 0,
    'checked' => $checkedCount,
    'synced' => $syncedCount,
    'failed' => $failedCount,
    'total' => count($dealsToSync),
    'synced_deals' => $syncedDeals,
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
 * Получение списка сделок для ретросинхронизации номеров заказов
 * Используем отдельные запросы вместо сложного LOGIC-фильтра
 */
function getDealsForOrderSync($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_1C_ORDER_ID'];
    $invoiceField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'];
    
    // Исключаем завершённые стадии
    $excludeStages = ['LOSE', 'C1:LOSE', 'WON', 'C1:WON'];
    
    // === Запрос 1: Розница, пустой номер заказа ===
    $filter1 = [
        'CATEGORY_ID' => $config['deal_categories']['retail'],
        'STAGE_ID' => ['PREPAYMENT_INVOICE', 'EXECUTING'],
        "!$orderField" => false,  // поле пустое
    ];
    
    $db1 = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'DESC'],
        $filter1,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $invoiceField, 'UF_*'],
        false,
        ['nTopCount' => $config['batch_size'] ?? 30]
    );
    
    if ($db1) {
        while ($deal = $db1->Fetch()) {
            if (!in_array($deal['STAGE_ID'], $excludeStages)) {
                $deals[] = $deal;
            }
        }
    }
    
    // === Запрос 2: Опт, пустой номер заказа ===
    $filter2 = [
        'CATEGORY_ID' => $config['deal_categories']['wholesale'],
        'STAGE_ID' => ['C1:EXECUTING', 'C1:1', 'C1:FINAL_INVOICE'],
        "!$orderField" => false,
    ];
    
    $db2 = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'DESC'],
        $filter2,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $invoiceField, 'UF_*'],
        false,
        ['nTopCount' => $config['batch_size'] ?? 30]
    );
    
    if ($db2) {
        while ($deal = $db2->Fetch()) {
            if (!in_array($deal['STAGE_ID'], $excludeStages)) {
                $deals[] = $deal;
            }
        }
    }
    
    // === Запрос 3: Розница, заказ заполнен, но счет пустой ===
    $filter3 = [
        'CATEGORY_ID' => $config['deal_categories']['retail'],
        'STAGE_ID' => ['PREPAYMENT_INVOICE', 'EXECUTING'],
        "!$orderField" => false,  // заказ заполнен
        $invoiceField => false,    // но счет пустой
    ];
    
    $db3 = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'DESC'],
        $filter3,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $invoiceField, 'UF_*'],
        false,
        ['nTopCount' => $config['batch_size'] ?? 30]
    );
    
    if ($db3) {
        while ($deal = $db3->Fetch()) {
            if (!in_array($deal['STAGE_ID'], $excludeStages)) {
                $deals[] = $deal;
            }
        }
    }
    
    // === Запрос 4: Опт, заказ заполнен, но счет пустой ===
    $filter4 = [
        'CATEGORY_ID' => $config['deal_categories']['wholesale'],
        'STAGE_ID' => ['C1:EXECUTING', 'C1:1', 'C1:FINAL_INVOICE'],
        "!$orderField" => false,
        $invoiceField => false,
    ];
    
    $db4 = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'DESC'],
        $filter4,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $invoiceField, 'UF_*'],
        false,
        ['nTopCount' => $config['batch_size'] ?? 30]
    );
    
    if ($db4) {
        while ($deal = $db4->Fetch()) {
            if (!in_array($deal['STAGE_ID'], $excludeStages)) {
                $deals[] = $deal;
            }
        }
    }
    
    // Убираем дубликаты по ID
    $uniqueDeals = [];
    foreach ($deals as $deal) {
        $uniqueDeals[$deal['ID']] = $deal;
    }
    
    return array_values($uniqueDeals);
}

/**
 * Проверка наличия счета в таймлайне сделки
 * 
 * @param int $dealId ID сделки
 * @param string $invoiceNumber Номер счета
 * @return bool true если счет уже загружен
 */
function checkInvoiceInTimeline($dealId, $invoiceNumber) {
    try {
        // Ищем записи в таймлайне с упоминанием номера счета
        $dbTimeline = \CCrmTimeline::GetList(
            [],
            [
                'ENTITY_TYPE' => \CCrmOwnerType::Deal,
                'ENTITY_ID' => $dealId,
                '%COMMENT' => 'Счет_' . $invoiceNumber // Частичное совпадение
            ],
            false,
            ['nTopCount' => 1]
        );
        
        return $dbTimeline->Fetch() !== false;
        
    } catch (\Exception $e) {
        // Ошибка поиска не критична — считаем, что счета нет
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
 * Загрузка счета в таймлайн сделки (повторное использование из Шага 1)
 */
function uploadInvoiceToTimeline($dealId, $invoiceNumber, $pdfContent, $config) {
    try {
        $base64Pdf = base64_encode($pdfContent);
        
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => "Счет на оплату №{$invoiceNumber} от " . date('d.m.Y'),
                'FILES' => [["Счет_{$invoiceNumber}.pdf", $base64Pdf]]
            ]
        ];
        
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.add.json';
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && !empty($result['result'])) {
            Logger::info('Счет загружен в таймлайн', [
                'deal_id' => $dealId,
                'invoice_number' => $invoiceNumber
            ]);
            return true;
        }
        
        Logger::warning('Не удалось загрузить счет в таймлайн', [
            'deal_id' => $dealId,
            'error' => $result['error_description'] ?? 'unknown'
        ]);
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Ошибка загрузки счета в таймлайн', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
?>