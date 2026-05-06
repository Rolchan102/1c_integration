<?php
/**
 * Шаг 0.2: Тихая синхронизация расходов из 1С → Битрикс24
 * 
 * Назначение:
 *   • Проверяет, есть ли расход (наличный/безналичный) в 1С для сделки (по DNK_id = deal_id)
 *   • Если есть — заполняет ПУСТЫЕ поля в сделке:
 *     - UF_CRM_EXPENSE_NUMBER (если пусто)
 *     - UF_CRM_EXPENSE_DATE (если пусто)
 *     - UF_CRM_EXPENSE_TYPE (если пусто: 262=наличные, 263=безнал)
 *     - UF_CRM_EXPENSE_AMOUNT (если пусто)
 *     - UF_CRM_EXPENSE_ACCOUNT (если пусто — УИД счёта/кассы)
 *   • НЕ создаёт новые расходы, НЕ отправляет данные в 1С
 *   • Безопасен для повторного запуска (идемпотентен)
 * 
 * Режимы работы:
 *   • CLI: php step0_sync_expense.php [--limit=20] [--deal_id=123]
 *   • Web: GET /step0_sync_expense.php?deal_id=123
 *   • CRON: запуск каждые 15 секунд через crontab
 * 
 * @package Integration
 */

// ============================================================================
// === 0. ИНИЦИАЛИЗАЦИЯ ===
// ============================================================================

// ФИКС ДЛЯ CLI: определяем DOCUMENT_ROOT ДО любого require
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

// Загрузка бутстрапа для корректной работы Битрикс в CLI
if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php")) {
    require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");
}

// Авторизация как администратор для корректной работы фильтров Битрикс в CLI
if (PHP_SAPI === 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        $USER = new \CUser();
        $USER->Authorize(1);
    }
}

// Закрываем сессию, если активна
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Подключаем модули
\Bitrix\Main\Loader::includeModule('crm');

// Подключаем зависимости
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');

use Integration\Logger;

// Ручная проверка авторизации для веб-режима
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

// === ЗАГРУЗКА КОНФИГУРАЦИИ ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);

// === ПРОВЕРКА ДОСТУПА (только для веб) ===
if (PHP_SAPI !== 'cli') {
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

// Настройки для подавления деприкейтед-ошибок
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);

Logger::info('=== ЗАПУСК: Синхронизация расходов из 1С ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : ($USER->GetID() ?? 'anonymous')
]);

// ============================================================================
// === 1. ОПРЕДЕЛЕНИЕ РЕЖИМА РАБОТЫ ===
// ============================================================================

$dealIdParam = null;
$batchMode = true;

// Обработка параметров: CLI аргументы или GET/POST
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
} else {
    $dealIdParam = (int)($_GET['deal_id'] ?? $_POST['deal_id'] ?? 0);
    if ($dealIdParam) {
        $batchMode = false;
    }
}

// ============================================================================
// === 2. ПОЛУЧЕНИЕ СПИСКА СДЕЛОК ДЛЯ ПРОВЕРКИ ===
// ============================================================================

$dealsToCheck = $batchMode 
    ? getDealsForExpenseSync($config) 
    : [['ID' => $dealIdParam]];

if (empty($dealsToCheck) || ($dealIdParam && !$dealIdParam)) {
    Logger::info('Нет сделок для синхронизации расходов');
    echo json_encode(
        ['success' => true, 'checked' => 0, 'updated' => 0, 'message' => 'Нет сделок для проверки'], 
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

Logger::info('Найдено сделок для синхронизации расходов', ['count' => count($dealsToCheck), 'batch' => $batchMode]);

// ============================================================================
// === 3. ОБРАБОТКА КАЖДОЙ СДЕЛКИ ===
// ============================================================================

$checkedCount = 0;
$updatedCount = 0;
$failedCount = 0;
$updates = [];
$failures = [];

foreach ($dealsToCheck as $deal) {
    try {
        $dealId = (int)($deal['ID'] ?? $deal);
        if (!$dealId) continue;
        
        $checkedCount++;
        
        Logger::debug('Проверка расходов для сделки', [
            'deal_id' => $dealId,
            'mode' => $batchMode ? 'batch' : 'single'
        ]);
        
        // Шаг 1: Поиск расходов в 1С (проверяем оба типа: наличные и безналичные)
        $expense = findExpenseIn1C($dealId, $config);
        
        if (!$expense || empty($expense['Номер'])) {
            Logger::debug('Расход не найден в 1С для сделки', ['deal_id' => $dealId]);
            continue;
        }
        
        // Шаг 2: Получение текущих значений полей из сделки
        $crmDeal = \CCrmDeal::GetByID($dealId);
        if (!$crmDeal) {
            Logger::warning('Сделка не найдена в Битрикс24', ['deal_id' => $dealId]);
            continue;
        }
        
        // Поля для синхронизации
        $fieldsMap = [
            'number'  => $config['deal_fields']['UF_CRM_EXPENSE_NUMBER'] ?? 'UF_CRM_EXPENSE_NUMBER',
            'date'    => $config['deal_fields']['UF_CRM_EXPENSE_DATE'] ?? 'UF_CRM_EXPENSE_DATE',
            'type'    => $config['deal_fields']['UF_CRM_EXPENSE_TYPE'] ?? 'UF_CRM_EXPENSE_TYPE',
            'amount'  => $config['deal_fields']['UF_CRM_EXPENSE_AMOUNT'] ?? 'UF_CRM_EXPENSE_AMOUNT',
            'account' => $config['deal_fields']['UF_CRM_EXPENSE_ACCOUNT'] ?? 'UF_CRM_EXPENSE_ACCOUNT',
        ];
        
        $currentValues = [];
        foreach ($fieldsMap as $key => $fieldCode) {
            $currentValues[$key] = trim($crmDeal[$fieldCode] ?? '');
        }
        
        // Шаг 3: Парсинг данных из 1С
        $newData = parseExpenseFrom1C($expense, $config);
        
        // Шаг 4: Формирование обновлений ТОЛЬКО для пустых полей
        $updateFields = [];
        $updatedFields = [];
        
        foreach ($fieldsMap as $key => $fieldCode) {
            if (empty($currentValues[$key]) && !empty($newData[$key])) {
                $updateFields[$fieldCode] = $newData[$key];
                $updatedFields[$key] = $newData[$key];
            }
        }
        
        // Шаг 5: Выполнение обновления (если есть что обновить)
        if (!empty($updateFields)) {
            $updated = updateDealViaRest($dealId, $updateFields, $config);
            
            if ($updated) {
                $updatedCount++;
                $updates[] = [
                    'deal_id' => $dealId,
                    'updated' => $updatedFields,
                    'expense_type' => $newData['type'] === '262' ? 'cash' : 'bank'
                ];
                Logger::info('Поля расхода обновлены в сделке', [
                    'deal_id' => $dealId,
                    'fields' => $updatedFields,
                    'type' => $newData['type'] === '262' ? 'наличные' : 'безналичные'
                ]);
            } else {
                Logger::error('Не удалось обновить сделку через REST', [
                    'deal_id' => $dealId,
                    'fields' => $updateFields
                ]);
                $failedCount++;
            }
        } else {
            Logger::debug('Поля расхода уже заполнены, обновление не требуется', ['deal_id' => $dealId]);
        }
        
        // Пауза между запросами к 1С
        usleep(100000); // 100 мс
        
    } catch (\Exception $e) {
        $failedCount++;
        $errorMessage = $e->getMessage();
        $failures[] = [
            'deal_id' => $deal['ID'] ?? $deal ?? 'unknown',
            'error' => $errorMessage
        ];
        
        Logger::error('Ошибка синхронизации расходов', [
            'deal_id' => $deal['ID'] ?? $deal ?? 'unknown',
            'error' => $errorMessage
        ]);
    }
}

// ============================================================================
// === 4. ИТОГИ ===
// ============================================================================

Logger::info('=== ЗАВЕРШЕНИЕ: Синхронизация расходов ===', [
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total' => count($dealsToCheck)
]);

// Формирование результата
$result = [
    'success' => $failedCount === 0,
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total' => count($dealsToCheck),
    'updates' => $updates,
    'failures' => $failures,
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web'
];

// Вывод результата
if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
// ============================================================================

/**
 * Получение списка сделок для синхронизации расходов
 * 
 * Критерии отбора:
 * 1. Стадии: любые, где возможен расход (исполнение, отгрузка, финал)
 * 2. Поле "Номер расхода" ИЛИ "Дата расхода" — пустые
 * 3. Поле "№ заказа в 1С" — заполнено (иначе нет смысла проверять)
 * 
 * @param array $config Конфигурация
 * @return array Массив сделок
 */
function getDealsForExpenseSync($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_1C_ORDER_ID'] ?? 'UF_CRM_1C_ORDER_ID';
    $expenseNumberField = $config['deal_fields']['UF_CRM_EXPENSE_NUMBER'] ?? 'UF_CRM_EXPENSE_NUMBER';
    $expenseDateField = $config['deal_fields']['UF_CRM_EXPENSE_DATE'] ?? 'UF_CRM_EXPENSE_DATE';
    
    // Стадии для проверки (розница + опт)
    $stages = ['EXECUTING', 'C1:EXECUTING', 'C1:FINAL_INVOICE', 'C1:1', 'C1:2'];
    
    // Фильтр: стадия + хотя бы одно поле расхода пустое + номер заказа в 1С заполнен
    $filter = [
        'STAGE_ID' => $stages,
        "!$orderField" => false, // поле заказа не пустое
        [
            'LOGIC' => 'OR',
            [$expenseNumberField => ''],
            [$expenseNumberField => false],
            [$expenseDateField => ''],
            [$expenseDateField => false],
        ]
    ];
    
    $dbDeals = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filter,
        [
            'ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 
            $orderField, $expenseNumberField, $expenseDateField,
            $config['deal_fields']['UF_CRM_EXPENSE_TYPE'] ?? 'UF_CRM_EXPENSE_TYPE',
            $config['deal_fields']['UF_CRM_EXPENSE_AMOUNT'] ?? 'UF_CRM_EXPENSE_AMOUNT',
            $config['deal_fields']['UF_CRM_EXPENSE_ACCOUNT'] ?? 'UF_CRM_EXPENSE_ACCOUNT',
        ],
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );
    
    while ($deal = $dbDeals->Fetch()) {
        $deals[] = $deal;
    }
    
    return $deals;
}

/**
 * Поиск расхода в 1С по привязке к сделке (DNK_id)
 * Проверяет оба типа: /Payment/ (безнал) и /CashPayment/ (наличные)
 * 
 * @param int $dealId ID сделки
 * @param array $config Конфигурация
 * @return array|false Данные расхода или false
 */
function findExpenseIn1C($dealId, $config) {
    $baseUrl = rtrim($config['1c_base_url'], '/');
    $auth = $config['1c_auth'] ?? [];
    
    $authHeader = 'Basic ' . base64_encode(
        mb_convert_encoding(
            ($auth['login'] ?? '') . ':' . ($auth['password'] ?? ''),
            'UTF-8',
            'UTF-8'
        )
    );
    
    // Проверяем оба эндпоинта: безналичные и наличные расходы
    $endpoints = [
        "{$baseUrl}/Payment",      // Расход со счёта (безнал)
        "{$baseUrl}/CashPayment",  // Расход из кассы (наличные)
    ];
    
    $httpClient = new \Bitrix\Main\Web\HttpClient([
        'socketTimeout' => 30,
        'streamTimeout' => 30
    ]);
    $httpClient->setHeader('Authorization', $authHeader);
    $httpClient->setHeader('Accept', 'application/json');
    
    foreach ($endpoints as $url) {
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();
        
        if ($statusCode !== 200) {
            continue;
        }
        
        $list = @json_decode($response, true);
        if (!is_array($list)) {
            continue;
        }
        
        // Ищем расход с совпадающим DNK_id
        foreach ($list as $expense) {
            if (!is_array($expense)) continue;
            
            $expenseDnkId = (string)($expense['DNK_id'] ?? '');
            if ($expenseDnkId === (string)$dealId && !empty($expense['Номер'])) {
                // 🔧 Добавляем метку типа расхода для дальнейшей обработки
                $expense['_expense_type'] = (stripos($url, 'CashPayment') !== false) ? 'cash' : 'bank';
                return $expense;
            }
        }
    }
    
    return false;
}

/**
 * Парсинг данных расхода из 1С в формат для Битрикс24
 * 
 * @param array $expense Данные из 1С
 * @param array $config Конфигурация
 * @return array Массив полей для обновления
 */
function parseExpenseFrom1C($expense, $config) {
    $result = [];
    
    // Номер документа
    $result['number'] = trim($expense['Номер'] ?? '');
    
    // Дата: парсим из формата 1С "05.03.2026 0:00:00" → "2026-03-05"
    $rawDate = $expense['Дата'] ?? '';
    if (!empty($rawDate)) {
        try {
            $dt = \DateTime::createFromFormat('d.m.Y H:i:s', trim($rawDate));
            $result['date'] = $dt ? $dt->format('Y-m-d') : '';
        } catch (\Exception $e) {
            $result['date'] = '';
        }
    }
    
    // Тип расхода: 262 = наличные, 263 = безналичные
    $expenseType1C = $expense['_expense_type'] ?? '';
    $result['type'] = ($expenseType1C === 'cash') 
        ? ($config['payment_received']['values']['cash_expense'] ?? '262')  // наличные
        : ($config['payment_received']['values']['bank_expense'] ?? '263'); // безналичные
    
    // Сумма
    $amount = $expense['Сумма'] ?? $expense['СуммаОплаты'] ?? $expense['СуммаАванса'] ?? 0;
    $result['amount'] = !empty($amount) ? (float)$amount : '';
    
    // УИД счёта/кассы (если есть в ответе)
    $result['account'] = trim($expense['BankAccount'] ?? $expense['cashbox'] ?? $expense['УИД'] ?? '');
    
    return array_filter($result, fn($v) => $v !== '' && $v !== null);
}

/**
 * Парсинг даты из 1С в формат Битрикс24
 */
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
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
?>