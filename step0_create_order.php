<?php
/**
 * Шаг 0.1: ОПТИМИЗИРОВАННАЯ синхронизация полей счёта из 1С → Битрикс24
 * 
 * 🔧 Ключевые улучшения:
 *   • Прямой запрос к 1С: /orders/0/{deal_id} вместо перебора всех заказов
 *   • Кэширование ответов 1С в рамках одного запуска (защита от дублей)
 *   • Сокращённые паузы: 50 мс вместо 150 мс
 *   • Логирование времени выполнения для отладки
 */

if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php")) {
    require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");
}

if (PHP_SAPI === 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        $USER = new \CUser();
        $USER->Authorize(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('crm');
\Bitrix\Main\Loader::includeModule('iblock');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
use Integration\Logger;

if (PHP_SAPI !== 'cli' && !defined('BP_INCLUDE_MODE')) {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

Logger::init($config);

if (PHP_SAPI !== 'cli' && !defined('BP_INCLUDE_MODE')) {
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);

$startTime = microtime(true);
Logger::info('=== ЗАПУСК: Синхронизация полей счёта (ОПТИМИЗИРОВАННАЯ) ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : (defined('BP_INCLUDE_MODE') ? 'bp' : 'web'),
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : ($USER->GetID() ?? 'anonymous')
]);

// === ОПРЕДЕЛЕНИЕ РЕЖИМА ===
$dealIdParam = null;
$batchMode = true;

if (PHP_SAPI === 'cli') {
    global $argv;
    foreach ($argv as $arg) {
        if (preg_match('/^--deal_id=(\d+)$/', $arg, $m)) {
            $dealIdParam = (int)$m[1];
            $batchMode = false;
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $config['batch_size'] = (int)$m[1];
        }
    }
} elseif (!defined('BP_INCLUDE_MODE')) {
    $dealIdParam = (int)($_GET['deal_id'] ?? $_POST['deal_id'] ?? 0);
    if ($dealIdParam) $batchMode = false;
}

$dealsToCheck = $batchMode ? getDealsForInvoiceSync($config) : [['ID' => $dealIdParam]];

if (empty($dealsToCheck) || ($dealIdParam && !$dealIdParam)) {
    Logger::info('Нет сделок для синхронизации полей счёта');
    outputResult(['success' => true, 'checked' => 0, 'updated' => 0, 'message' => 'Нет сделок'], $batchMode);
    exit;
}

Logger::info('Найдено сделок для синхронизации', ['count' => count($dealsToCheck), 'batch' => $batchMode]);

// === КЭШ ДЛЯ 1С: чтобы не дёргать API много раз за один запуск ===
$oneCResponseCache = [];

$checkedCount = 0;
$updatedCount = 0;
$failedCount = 0;
$updates = [];
$failures = [];

foreach ($dealsToCheck as $deal) {
    try {
        $dealId = (int)($deal['ID'] ?? $deal);
        if (!$dealId) continue;
        
        $dealStartTime = microtime(true);
        $checkedCount++;
        
        Logger::debug('Проверка счёта', ['deal_id' => $dealId, 'mode' => $batchMode ? 'batch' : 'single']);
        
        // 🔧 ОПТИМИЗАЦИЯ: прямой запрос к 1С по конкретному deal_id
        $invoice = findInvoiceIn1CByDealId($dealId, $config, $oneCResponseCache);
        
        if (!$invoice || empty($invoice['Номер'])) {
            Logger::debug('Счёт не найден в 1С', ['deal_id' => $dealId]);
            continue;
        }
        
        $crmDeal = \CCrmDeal::GetByID($dealId);
        if (!$crmDeal) {
            Logger::warning('Сделка не найдена в Битрикс24', ['deal_id' => $dealId]);
            continue;
        }
        
        $invoiceNumberField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] ?? 'UF_CRM_INVOICE_NUMBER';
        $invoiceDateField = $config['deal_fields']['UF_CRM_INVOICE_DATE'] ?? 'UF_CRM_INVOICE_DATE';
        
        $currentNumber = trim($crmDeal[$invoiceNumberField] ?? '');
        $currentDate = trim($crmDeal[$invoiceDateField] ?? '');
        $newNumber = trim($invoice['Номер'] ?? '');
        $newDate = parse1CDate($invoice['Дата'] ?? $invoice['ДатаСчета'] ?? '');
        
        $updateFields = [];
        $updatedFields = [];
        
        if (empty($currentNumber) && !empty($newNumber)) {
            $updateFields[$invoiceNumberField] = $newNumber;
            $updatedFields['number'] = $newNumber;
        }
        if (empty($currentDate) && !empty($newDate)) {
            $updateFields[$invoiceDateField] = $newDate;
            $updatedFields['date'] = $newDate;
        }
        
        if (!empty($updateFields)) {
            $updateStart = microtime(true);
            $updated = updateDealViaRest($dealId, $updateFields, $config);
            $updateDuration = round((microtime(true) - $updateStart) * 1000);
            
            if ($updated) {
                $updatedCount++;
                $updates[] = ['deal_id' => $dealId, 'updated' => $updatedFields];
                Logger::info('✅ Поля обновлены', [
                    'deal_id' => $dealId,
                    'fields' => $updatedFields,
                    'update_time_ms' => $updateDuration
                ]);
            } else {
                Logger::error('❌ Не удалось обновить сделку', ['deal_id' => $dealId, 'fields' => $updateFields]);
                $failedCount++;
            }
        } else {
            Logger::debug('Поля уже заполнены', ['deal_id' => $dealId]);
        }
        
        // 🔧 СОКРАЩЁННАЯ ПАУЗА: 50 мс вместо 150 мс
        usleep(50000);
        
        $dealDuration = round((microtime(true) - $dealStartTime) * 1000);
        Logger::debug('Обработка сделки завершена', ['deal_id' => $dealId, 'duration_ms' => $dealDuration]);
        
    } catch (\Exception $e) {
        $failedCount++;
        $failures[] = ['deal_id' => $deal['ID'] ?? $deal ?? 'unknown', 'error' => $e->getMessage()];
        Logger::error('Ошибка синхронизации', ['deal_id' => $deal['ID'] ?? $deal ?? 'unknown', 'error' => $e->getMessage()]);
    }
}

$totalDuration = round((microtime(true) - $startTime) * 1000);

Logger::info('=== ЗАВЕРШЕНИЕ ===', [
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total_duration_ms' => $totalDuration,
    'avg_per_deal_ms' => $checkedCount > 0 ? round($totalDuration / $checkedCount) : 0
]);

outputResult([
    'success' => $failedCount === 0,
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total' => count($dealsToCheck),
    'updates' => $updates,
    'failures' => $failures,
    'timestamp' => date('Y-m-d H:i:s'),
    'performance' => [
        'total_ms' => $totalDuration,
        'avg_per_deal_ms' => $checkedCount > 0 ? round($totalDuration / $checkedCount) : 0
    ]
], $batchMode);

// ============================================================================
// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
// ============================================================================

function getDealsForInvoiceSync($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] ?? 'UF_CRM_INVOICE_NUMBER';
    $invoiceNumberField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] ?? 'UF_CRM_INVOICE_NUMBER';
    $invoiceDateField = $config['deal_fields']['UF_CRM_INVOICE_DATE'] ?? 'UF_CRM_INVOICE_DATE';
    
    $stages = ['EXECUTING', 'C1:EXECUTING', 'C1:FINAL_INVOICE', 'C1:1'];
    
    $filter = [
        'STAGE_ID' => $stages,
        "!$orderField" => false,
        [
            'LOGIC' => 'OR',
            [$invoiceNumberField => ''],
            [$invoiceNumberField => false],
            [$invoiceDateField => ''],
            [$invoiceDateField => false],
        ]
    ];
    
    $dbDeals = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filter,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $invoiceNumberField, $invoiceDateField],
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );
    
    while ($deal = $dbDeals->Fetch()) {
        $deals[] = $deal;
    }
    return $deals;
}

/**
 * 🔧 ОПТИМИЗАЦИЯ: Прямой запрос к 1С по конкретному deal_id
 * Использует кэш, чтобы не дёргать API дважды за один запуск
 */
function findInvoiceIn1CByDealId($dealId, $config, &$cache) {
    $baseUrl = rtrim($config['1c_base_url'], '/');
    $auth = $config['1c_auth'] ?? [];
    
    // 🔧 ПРЯМОЙ ЗАПРОС: /orders/0/{deal_id} вместо /orders/0/0
    $url = "{$baseUrl}/orders/0/{$dealId}";
    $cacheKey = "deal_{$dealId}";
    
    // Проверяем кэш
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(
                mb_convert_encoding(($auth['login'] ?? '') . ':' . ($auth['password'] ?? ''), 'UTF-8', 'UTF-8')
            )
        ]
    ]);
    
    $requestStart = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $requestDuration = round((microtime(true) - $requestStart) * 1000);
    curl_close($ch);
    
    if ($curlError) {
        Logger::debug('cURL ошибка при запросе к 1С', ['deal_id' => $dealId, 'error' => $curlError]);
        return false;
    }
    
    if ($httpCode !== 200) {
        // 🔧 Если прямой запрос вернул 404 — пробуем перебор всех (fallback)
        if ($httpCode === 404) {
            Logger::debug('Прямой запрос вернул 404, пробуем fallback', ['deal_id' => $dealId]);
            return findInvoiceIn1CFallback($dealId, $config, $cache);
        }
        Logger::debug('HTTP ошибка при запросе к 1С', ['deal_id' => $dealId, 'http_code' => $httpCode]);
        return false;
    }
    
    $invoice = json_decode($response, true);
    
    // Проверяем, что это действительно наш счёт
    if (is_array($invoice) && isset($invoice['DNK_id']) && (string)$invoice['DNK_id'] === (string)$dealId) {
        $cache[$cacheKey] = $invoice;
        Logger::debug('✅ Счёт найден прямым запросом', [
            'deal_id' => $dealId,
            'invoice_number' => $invoice['Номер'] ?? '',
            'request_time_ms' => $requestDuration
        ]);
        return $invoice;
    }
    
    // Fallback: если прямой запрос вернул не то — ищем в общем списке
    Logger::debug('Прямой запрос вернул неожиданный ответ, пробую fallback', ['deal_id' => $dealId]);
    return findInvoiceIn1CFallback($dealId, $config, $cache);
}

/**
 * Fallback: перебор всех заказов (если прямой запрос не сработал)
 * С кэшированием ответа, чтобы не дёргать API дважды
 */
function findInvoiceIn1CFallback($dealId, $config, &$cache) {
    $baseUrl = rtrim($config['1c_base_url'], '/');
    $auth = $config['1c_auth'] ?? [];
    
    // 🔧 КЭШИРУЕМ ответ /orders/0/0 на весь запуск скрипта
    if (!isset($cache['_all_orders'])) {
        $url = "{$baseUrl}/orders/0/0";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode(
                    mb_convert_encoding(($auth['login'] ?? '') . ':' . ($auth['password'] ?? ''), 'UTF-8', 'UTF-8')
                )
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $list = json_decode($response, true);
            $cache['_all_orders'] = is_array($list) ? $list : [];
        } else {
            $cache['_all_orders'] = [];
        }
    }
    
    // Ищем в кэшированном списке
    foreach ($cache['_all_orders'] as $invoice) {
        if (isset($invoice['DNK_id']) && (string)$invoice['DNK_id'] === (string)$dealId) {
            $cache["deal_{$dealId}"] = $invoice;
            return $invoice;
        }
    }
    
    return false;
}

function parse1CDate($date1C) {
    if (empty($date1C)) return '';
    try {
        $dt = \DateTime::createFromFormat('d.m.Y H:i:s', trim($date1C));
        if ($dt) return $dt->format('Y-m-d');
        $dt = new \DateTime($date1C);
        return $dt->format('Y-m-d');
    } catch (\Exception $e) {
        return '';
    }
}

function updateDealViaRest($dealId, $fields, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.update.json';
        $postData = http_build_query(['id' => (int)$dealId, 'fields' => $fields]);
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $result = json_decode($response, true);
        return ($httpCode === 200 && !empty($result['result']));
    } catch (\Exception $e) {
        Logger::error('Ошибка обновления сделки через REST', ['deal_id' => $dealId, 'error' => $e->getMessage()]);
        return false;
    }
}

function outputResult($result, $batchMode) {
    if (PHP_SAPI === 'cli') {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } elseif (defined('BP_INCLUDE_MODE')) {
        return $result;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
?>